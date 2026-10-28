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
- **Enrolment** (correction after review, 25 Oct). Each of these three paths enrols the new tenant in the policy with
  one `tenant_setting_changes` row: setting `feature_defaults`, the switches it started with, and how it was made.
  No schema change.
- **The business is set for the first time** (`TenantBusinessService::changeBusiness`, old value empty):
  - **Enrolled tenant** (made under the policy): the business rows (`whole_counted_stock`, `packs`, `counter_packs`,
    `medical_counter`) fill **only switches it has never set**. A switch already on, or explicitly off, is kept.
    Each change is recorded.
  - **Older tenant** (no enrolment — made before this patch, business never set): **its switches are kept exactly.**
    The business rows are only **proposed** in Master's message ("Proposed for this business, not switched on …"),
    for the owner to switch on by hand under Business & switches.
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

## 4. As built — commits `98e383a0` + `40f0c2ae` (final code) (branch `feat/rollout-completion-20261025`, from production `4b745532`)

Code only. No migration, no new route, no new table; the system map is unchanged (229 tables, 736 screens).

| Area | Files |
|---|---|
| The rule | `app/Support/LineQuantity.php` (`packTakesParts`) |
| The server's new-screen lines | `app/Support/PackLines.php` (packs, free), `app/Support/ReturnLines.php` |
| The page | `resources/views/tenant/purchasing/partials/entry-area.blade.php` (one partial: PO, GRN, supplier return, workspace) |
| The policy | `app/Support/TenantFeatureDefaults.php` (new) |
| Where it is used | `SelfSignupService`, `Central\TenantController@store`, `DemoProvisioner::featuresFor`, `TenantBusinessService::changeBusiness` (+ the Master message in `TenantBusinessController`) |

The Master message after a first business set now ends with, for example:
- an enrolled tenant: `Switched on as this business's defaults: "Whole numbers for counted items in purchasing and
  stock".`
- an older tenant: `Proposed for this business, not switched on (this tenant was made before the defaults policy):
  "Whole numbers for counted items in purchasing and stock", "Packs (box · strip · tablet)", "Packs at the normal
  counter" — switch them on under Business & switches if wanted.`

## 5. Verification

| Check | Result |
|---|---|
| Focused MySQL (`RolloutDefaultsMySqlTest` + `PurchaseStockQuantityMySqlTest`, DB set `_roc`) | `OK, but there were issues!` `Tests: 11, Assertions: 146, PHPUnit Deprecations: 1.` — 11 / 11 |
| Policy unit test (`TenantFeatureDefaultsTest`) | `OK (7 tests, 198 assertions)` |
| Every compiled Blade view (`CompiledBladeSyntaxTest`) | `OK (1 test, 3 assertions)` |
| Local browser walk of the entry area (test store `pwtest`, server from this worktree) | 13 / 13, no page errors |
| The same walk on the code before this patch (`7b4719c6`, the entry area production runs) | 3 failed: "3 Piece" shown for 2.5; 2.5 refused with the switch OFF; the walk stopped there — it can tell the two apart |

The demo test is the real path: `DemoProvisioner::provision` creates the tenant database, a switch is changed by hand,
then `DemoResetService::resetTenantCode` (what the nightly reset runs: provision fresh, then `demo:seed`) rebuilds it.
For the inventory and pharmacy demos the defaults came back and **the sample data seeded with them on**
(pharmacy: packs, medical counter and whole numbers; inventory: packs, counter cartons and whole numbers).

**The browser walk** (13 checks):

| Switch | What it checked |
|---|---|
| OFF | 2.5 pieces shows "2.5 Piece", goes in the list and posts as 2.500, plus 0.500 free |
| OFF | an order of 2.5 pieces: "Receive" fills 2.5, not 2 |
| ON | 2.5 still shows "2.5 Piece" (never "3"); refused with "Piece: a whole number, please."; free 0.5 refused with "Free: a whole number of Piece, please."; nothing written |
| ON | kg still shows "1.25 Kilogram" |

**Sabotage** (each guard broken on purpose, then restored; the worktree was checked clean afterwards):

| Broken | Caught by |
|---|---|
| whole numbers bundled with the purchasing module | 2 unit tests |
| refunds without a bill for a live pharmacy | 2 unit tests |
| `fill()` overwrites a switch the tenant set | 1 unit test |
| the parts rule ignores the switch | `test_the_new_screens_take_a_part…` |
| the page always told "parts allowed" | `test_the_entry_area_is_told_the_switch` |
| the first-business fill ignores existing choices | `test_the_first_business_fills_only…` |
| demos back to the old hard-coded switches | `test_every_demo_starts_with_its_business_defaults` |
| signup sets no switches | `test_a_signup_starts_with_the_screens_its_plan_carries` |

**Related regression:** 40 MySQL classes that touch switches, purchasing, packs, signup, Master or the demos, run on the frozen commit (worktree `pos-saas-verify-upv` at `98e383a0`, DB set `_rocr`; the freeze file shows the same HEAD and a clean tree before and after):

| Suite | Runner summary | Result |
|---|---|---|
| MySQL, 40 related classes | `OK, but there were issues!` `Tests: 322, Assertions: 4837, PHPUnit Deprecations: 1, Skipped: 1.` | **321 passed, 1 skipped** (the GeoIP real-file check, the same single skip as earlier full runs) |
| `tests/Unit` | `FAILURES!` `Tests: 129, Assertions: 748, Failures: 3, Skipped: 4.` | **the three failures that predate this work** (`CateringClientFeedbackUiRegressionTest`, `EscPosReportPayloadTest`, `DeliveryRiderReassignmentRegressionTest`; they fail the same on production) |

Not run: the full MySQL suite (about 2,460 tests). The patch changes no money or GL path; it changes how purchase quantities are checked and which switches a new tenant starts with, and every class touching those ran.

### Correction after review — commit `40f0c2ae` (final code)

**The review found:** `changeBusiness()` applied the business defaults whenever the previous business was empty. An
older tenant whose business was never set could get `whole_counted_stock` when its business was first chosen.

**The fix:** business defaults apply automatically only to tenants **enrolled** in the policy at creation. An older
tenant keeps its switches exactly and is only shown proposals (section 2).

The affected tests ran on the frozen commit (worktree `pos-saas-verify-upv` at `40f0c2ae`, DB set `_rocr`; the freeze
file shows the same HEAD and a clean tree before and after). As the reviewer asked, there was no new full-suite cycle.

| Suite | Runner summary | Result |
|---|---|---|
| MySQL: `RolloutDefaultsMySqlTest`, `MasterUniverseMySqlTest`, `TrialSignupQueueMySqlTest`, `MarketSignupMySqlTest`, `RecaptchaTrialMySqlTest`, `TrialBillingPeriodMySqlTest`, `PharmacyDemoMySqlTest` | `OK, but there were issues!` `Tests: 58, Assertions: 979, PHPUnit Deprecations: 1.` | **58 / 58** |
| `TenantFeatureDefaultsTest` | `OK (7 tests, 198 assertions)` | 7 / 7 |

The new regression test is `test_an_older_tenant_with_no_business_keeps_its_switches_and_is_only_shown_proposals`.
- **What it checks:** an older tenant with its own switches, and one with none, have their business set. Their
  stored switches come out byte-for-byte as before. No switch change is recorded. The message lists the proposals.
- **Proven able to fail:** with every tenant treated as enrolled, it fails (`Tests: 1, Assertions: 2, Failures: 1`);
  the code was restored afterwards.

## 6. What deploying this patch changes, tenant by tenant

**Live tenants:** no switch changes at deploy, and none of their screens behaves differently, with one exception:
- every live tenant with the new purchasing screens has `whole_counted_stock` ON (kashiffood, kashifkitchen, mohsin,
  demo), so the parts rule answers exactly as today;
- **the exception, an improvement:** on those tenants, "Receive" from an order with a kg item due in parts (2.5 kg)
  now fills 2.5 instead of 2.

**Demos:** each one takes the policy at its next nightly reset (04:00 UTC). No manual reset is needed or done.

**New tenants:** the policy (section 2).

## 7. Proposed activation matrix — for review, nothing activated

Production switches read on 25 Oct, after the 04:00 UTC demo reset:

| Tenant | Switches now | After deploy | Proposed after that |
|---|---|---|---|
| kashiffood (live) | product screen, purchasing, workspace, whole numbers, report delivery | unchanged | — |
| kashifkitchen (live) | product screen, purchasing, workspace, whole numbers, void purge | unchanged | — |
| mohsin, demo | product screen, purchasing, workspace, whole numbers | unchanged | — |
| tawakalkashif (live) | product screen | unchanged | purchasing, workspace, whole numbers: not applicable (no purchasing or stock module) |
| **khatribiryani (live)** | product screen, void purge | unchanged | **turn on `purchasing_v2` + `purchase_workspace`; keep `whole_counted_stock` OFF**. Its counted items bought in parts (33 receipt + 33 invoice lines) are then taken on the new screens. Do it after the deploy and its verification, on approval. Check its screens by rendering them only — **no test transactions on khatribiryani** |
| retaildemo | product screen | product screen (no stock module: no packs) | — |
| inventorydemo | product screen | + purchasing, workspace, whole numbers, packs, counter cartons | — |
| restaurantdemo | product screen | unchanged | — |
| restaurantprodemo | product screen | + purchasing, workspace (restaurant: no whole numbers) | — |
| enterprisedemo, financedemo | product screen | + purchasing, workspace | — |
| pharmacydemo | product screen, packs, purchasing, medical counter, return without bill | + workspace, whole numbers | — |

**Rollback depends on the activation state** (correction after review, 25 Oct). It is not simply "code only".

- **Before khatribiryani is activated:** reverting the commit restores the previous rules. No live tenant notices
  except the "Receive" fill for kg remainders, which goes back to dropping the remainder.
- **After khatribiryani is activated:** reverting the code alone brings back the blocker. Its counted items in parts
  would be refused again on the new screens it is using.
  - So a revert must come with a decision for khatribiryani: either keep the code, or switch its `purchasing_v2` and
    `purchase_workspace` off first, back to the old screens, which take parts.
  - That is the owner's call: its staff lose the new screens.
  - Documents already made on the new screens stay valid either way.
- **Tenants created while the patch was live** keep the switches and the enrolment row they were given. Those are
  ordinary switches, changeable in Master. A revert does not remove them.
- **Fix forward is the default.** A backup restore is not a rollback; it needs its own recovery plan
  (`docs/status/release-deploy-2026-10-25.md`).
