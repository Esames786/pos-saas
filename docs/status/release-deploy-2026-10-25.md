# Deployment record — unified W2, units & packs, W3/W4 (24–25 Oct 2026)

**Deployed:** `4b745532` (`Merge UNIFIED-W3W4: …`) on production branch `feat/14d-2-plan-upgrade-requests`. Its files
are identical to the reviewed candidate: tested revision `7b4719c6` plus the docs-only `ef43db2c` (`git diff ef43db2c
4b745532` lists no file). deploy.sh finished at 22:14 UTC on 24 Oct (03:14 Karachi, 25 Oct).

**Approval:** the owner and the reviewer approved the push, the deploy and a staged rollout to existing tenants
(25 Oct). The rules were: preserve history, documents, stock, balances, product settings and permissions; no resets,
backfills, corrections or reclassification; Owner-only workspace permissions.

Manifest: `docs/status/release-manifest-2026-10-24.md`. Check scripts: `docs/status/release-2026-10-24-checks/`.

## 1. Before the deploy

| Check | Result |
|---|---|
| Production base | server, origin and the local branch all at `ed9a8431`, the reviewed base |
| Push | `ed9a8431..4b745532`; origin == local HEAD checked in a separate step before deploy |
| Census | 13 tenants in master, all active and migrated; 13 `pos_tenant_*` schemas; none unlisted. `product_pack_units.factor` `int unsigned` on all 13; rows only on pharmacydemo (78, 1–300) |
| Long transactions (> 10 s) | none |
| Snapshots saved | switches (`/tmp/features_before.json`), permissions (`/tmp/perm_before.json`), table fingerprints (`/tmp/recon_before.json`); copies in `/root/release-20261024/` |
| Smoke | `tb_diff=0.00`, `neg_on_disallowed=0` and `department_negative=0` on all 13 tenants |

**Backup:** `tenants:backup --no-storage`, with no `--tenant` option, into
`storage/app/private/backups/20261024_221237`.
- It holds master plus all 13 tenants. Each dump ends with mysqldump's "Dump completed" line.
- The coverage check printed `tenants in master=13, schemas on server=13, backed up OK=13 — COVERAGE OK`.

## 2. The deploy

- deploy.sh: `tenants ok=13 skipped=0`, `DEPLOY COMPLETE`.
- **Migrations, checked in each tenant DB** (`migration_check.php`) rather than taken from deploy.sh's "OK":
  - 0 pending tenant migrations on all 13;
  - `factor` is `decimal(15,4)` on all 13;
  - `pack_size` is on every line table;
  - the nettings and credit-reallocation tables exist;
  - `entered_unit_code` and `submission_key` exist.

  Result: `MIGRATIONS COMPLETE on every tenant`. pharmacydemo kept its 78 pack sizes, now 1.0000 to 300.0000, with
  0 fractional values.
- **Switches:** `SWITCHES OK: every tenant exactly as before (13 tenants)`.
- **Permissions:** `PERMISSIONS OK: no role lost anything, no user lost a role`. On every tenant the role named
  "Owner" gained exactly `tenant.purchases.workspace`, `.store` and `.receipt`, and nothing else changed.
- **Fingerprint** (`recon_snapshot.php`: every table, every row that existed before, `updated_at` left out):
  - On every tenant, every table is identical except two kinds:
    - `role_has_permissions`: the Owner's 3 grants. It has no id column, so the script reports it as
      "unexplained"; it is the same change the permission diff names.
    - print-agent and print-job rows, updated by live use and the scheduler.
  - GL debits and credits, stock and supplier balances are the same on all 13 tenants.
- **Screens:** `page_probe.php` renders the key screens through the app as the Owner (GET only). It ran on all 13
  tenants with no 5xx and no error page.
  - Every 403 follows the plan's modules:
    - no purchasing at restaurantdemo, retaildemo and tawakalkashif;
    - no POS at kashifkitchen;
    - no Cash Book at inventorydemo and pharmacydemo.
  - `/purchases/new` was closed everywhere.

## 3. Staged activation

All switches were turned on through `TenantBusinessService::setFeatures`, the Master screen's path. Each change is
in `tenant_setting_changes` with the note "UNIFIED-W3W4 staged rollout (owner-approved 25 Oct)". **No switch was
turned off.**

Each group was verified before the next by:
- rendering screens as the Owner and as non-owner roles;
- the smoke check.

These are machine checks made at night (22:27–22:38 UTC). No real user had worked on the new screens between groups.

### Pilot — restaurantprodemo (public demo, restaurant plan with purchasing and recipes)

**Turned on:** `purchasing_v2`, `purchase_workspace`, `whole_counted_stock`. `product_screen_v2` was already on.

**Browser walk on production** (`pilot_walk.mjs`; logged in as the demo Owner; database checks read-only): **24 / 25,
then the 25th confirmed separately.**

| Step | Result |
|---|---|
| Classic Beef Burger in the one product form | Recipe tab with its 5 lines; Save with no change accepted; recipe rows unchanged |
| Complete purchase: 10 buns + 5 kg rice, invoice, part payment | payment suggested 1,700; posted together as receipt, invoice and payment; stock +10 / +5; supplier owed +700 |
| 2.5 buns | refused; nothing saved |
| Receive now, invoice later | receipt only; stock +4; balance unchanged |
| Invoice for that receipt | shows Rs 80.00; paying 999 refused ("Payment — nothing was saved … Rs 80.00"); then 80 posted, invoice and payment only, stock not received again |
| Phone width | no sideways scroll |
| Page errors | none |

**The 25th check, 2.5 buns:** my walk read the page after pressing Post, which replaces the message. Read straight
after "Put in the list" (`pilot_whole.mjs`), the screen says **"Piece: a whole number, please."** Nothing was saved
either time.

**Non-owner (Demo role):** the sidebar's "Purchases" opens `/goods-receipts`; `/purchases/new` answers 403.

**Books:** smoke `tb_diff=0.00`. The fingerprint shows only the pilot's own postings: stock +19, supplier +700, one
cash account.

**The demo reset at 04:00 UTC every night rebuilds this tenant, and its switches go back to `DemoProvisioner`'s
defaults.**

### Groups

| Group | Tenant | Turned on | Checked as |
|---|---|---|---|
| 1 | mohsin (no products) | `product_screen_v2`, `purchasing_v2`, `purchase_workspace`, `whole_counted_stock` | Owner |
| 1 | demo (internal, not reset nightly) | the same four | Owner, Cashier, Manager |
| 1 | kashiffood | `purchase_workspace`, `whole_counted_stock` (it already had `product_screen_v2`, `purchasing_v2`) | Owner, Dine In, Delivery |
| 2 | tawakalkashif | `product_screen_v2` | Owner, Tawakkal Counter |
| 2 | khatribiryani | `product_screen_v2` | Owner, Accounts, Dine In, Manager |
| 3 | kashifkitchen | `product_screen_v2`, `purchasing_v2`, `purchase_workspace`, `whole_counted_stock` | Owner, Owner (No Amounts), Owner (No Amounts) + Reports |

**What every check showed:**
- **Owners:** "Purchases" in the sidebar opens `/purchases/new` (200).
- **Non-owners:** keep exactly their screens. "Purchases" opens the first purchasing page they may use, and
  `/purchases/new` is 403 for them. kashiffood's Dine In and Delivery roles have no purchasing permission, so they
  see no purchasing item, as before.
- **kashifkitchen:** a catering dish, a material, cost blocks and recipes all render on the new form, none slower
  than 1.5 s.
- **Smoke:** clean for every group.

### Readiness facts behind the decisions (`readiness.php`, read-only)

- **Counted items in parts** (unit type not weight / volume / length):
  - khatribiryani: 33 Goods Receipt lines, 33 supplier-invoice lines and 1 stock balance;
  - every other tenant: none.
- **The new purchasing entry area takes whole numbers only for counted items whenever `purchasing_v2` is on.** This
  has been so since PURCHASING-V2-1, before this release; `allow_decimal` comes from the unit type alone.
  `whole_counted_stock` adds the same rule on the server and in stock screens.
- **kashifkitchen:**
  - all 6 items received in the last 60 days are active and purchasable, so the new search finds them;
  - no extra charges;
  - its "Owner (No Amounts)" roles already hold invoice and payment permissions;
  - amount hiding (`shifts.view-amounts`) never covered purchasing screens. The new screens hide nothing less than
    the old.

## 4. Each tenant now

| Tenant | Switches on | Held or not applicable, and why |
|---|---|---|
| kashiffood (live) | `product_screen_v2`, `purchasing_v2`, `purchase_workspace`, `whole_counted_stock`, `report_delivery_v2` | packs / counter packs: restaurant, not relevant |
| kashifkitchen (live) | `product_screen_v2`, `purchasing_v2`, `purchase_workspace`, `whole_counted_stock`, `void_purge` | packs / counter packs: catering, not relevant |
| khatribiryani (live) | `product_screen_v2`, `void_purge` | **HELD `purchasing_v2`, `purchase_workspace`, `whole_counted_stock`:** it buys counted items in parts (33 + 33 lines), and the new entry area would refuse those entries. Packs: not relevant |
| tawakalkashif (live) | `product_screen_v2` | purchasing, workspace, whole numbers: **not applicable**, no purchasing or stock modules in its plan |
| mohsin | `product_screen_v2`, `purchasing_v2`, `purchase_workspace`, `whole_counted_stock` | packs: restaurant |
| demo (internal) | the same four | packs: restaurant |
| restaurantprodemo (demo) | the same four (pilot) | **reverts at the 04:00 UTC reset** |
| enterprisedemo, financedemo, inventorydemo, pharmacydemo, restaurantdemo, retaildemo (demos) | unchanged from before | **HELD:** a switch set by hand is erased by the nightly reset; durable only through `DemoProvisioner` defaults (section 5) |

## 5. Unfinished

1. **Defaults for new tenants.** None exist today: self-signup and Master create set no switches, and
   `DemoProvisioner` sets `product_screen_v2` (+ the pharmacy set) for demos only. This needs a small code change,
   its own tests and its own deploy. Proposed rule, to be approved:

   | Switch | Default |
   |---|---|
   | `product_screen_v2` | all |
   | `purchasing_v2` + `purchase_workspace` + `whole_counted_stock` | where the plan has purchasing |
   | `packs` | pharmacy and mart |
   | `counter_packs` | mart |
   | `medical_counter`, `return_no_bill` | pharmacy |
   | `void_purge`, `report_delivery_v2` | never by default |

2. **khatribiryani's purchasing.** It needs the entry area to accept parts of a counted item while
   `whole_counted_stock` is off, like the server rule. That is a code change. After it, khatribiryani can have
   `purchasing_v2` and the workspace, with `whole_counted_stock` kept OFF.
3. **The six reset demos.** They follow item 1.
4. **Entry area display.** While a fraction of a counted item is typed, the summary shows "3 Piece" beside "Rs 50"
   (2.5 × 20); the line cannot be added. Cosmetic.
5. **Workspace permissions for roles other than Owner:** none granted, as instructed.
6. **Carried from the manifest:** the 3 pre-existing default-suite failures; English label fixes in purchasing; units &
   packs remaining items; RESET-BALANCES-1.

## 6. Rollback position

Rollback position after the pilot:
- Production now holds documents made through the workspace (the pilot's, on the demo; the nightly reset removes
  them).
- Live tenants may create workspace documents from the morning of 25 Oct.
- From then on, code rollback is **not safe**: roll forward only (manifest §8).

The backup `20261024_221237` is the restore point for a data problem, decided per tenant.
