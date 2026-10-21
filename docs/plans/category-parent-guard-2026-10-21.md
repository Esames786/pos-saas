# CATEGORY-PARENT-GUARD-1 — a category can never sit inside its own sub-category (21 Oct 2026)

## Why

On 21 Oct 2026 (12:39–1:07 PM PKT) Khatri's POS lost every category tab. Someone on the Categories screen set each
main category's "Parent Category" to one of its own sub-categories. That made loops (A inside B, B inside A). The POS
shows only categories with no parent, so all of them vanished. It was fixed by putting the 12 parents back from the
00:02 UTC backup.

The screen allowed it because the parent list offers the category's own sub-categories and the server never checks
for a loop. Nobody can say which user did it: categories keep no "changed by", `users.last_login_at` is never filled
and tenant sessions keep no user.

## What changes

1. **Parent list** on Edit Category leaves out the category itself and everything under it (children, grandchildren…).
   Each choice shows its full path ("Chicken Biryani › Biryani Saadi"), so a main category and a sub-category with
   similar names can be told apart. There is a one-line hint under the box.
2. **Server check** on save: a parent that is the category itself or anything under it is refused with a plain
   English message. Nothing is saved. This also covers an old open tab or a hand-made request.
3. **"Last changed by"**: a new nullable `categories.updated_by` column. Create and Edit fill it in with the logged-in
   user. The Edit page shows "Last changed by <name>, <date time>". Old rows stay blank. The code writes the column only
   once it exists (schema-first rule), so the seconds between `git pull` and `migrate` in `deploy.sh` cannot fail a save.

Not changed: the Create screen's rules (a new category has no children, so it cannot make a loop); who may edit
categories (the counter roles on Khatri also hold this permission, which is the owner's call); bulk import (it only
creates top-level categories by name); onboarding commands and seeders.

## Impact (from the system map)

- **Tables:** `categories` (+1 nullable column `updated_by`, no FK, no backfill; 25 rows on the largest tenant).
  The Edge snapshot reads named columns (`EdgeBootstrapService`), so the new column does not reach it.
- **Screens:** `/categories/create`, `/categories/{category}/edit`, `POST /categories`, `PUT /categories/{category}`.
  POS, reports, products and departments only read `parent_id`, which this never writes differently for a valid save.
- **Also protected:** the code that walks the parent chain. `SalesReportEngine` uses a recursive CTE that runs until
  MySQL's recursion limit on a loop. `NonStockCostAccounts::forCategory` already has a seen-guard.
- **Tests:** new `tests/MySql/CategoryParentGuardMySqlTest.php`, run over real HTTP. Existing
  `CategoryBranchScopeMySqlTest` (the same controller's branch rule) is re-run.

## Deploy

Additive. Run `tenants:backup` first; `deploy.sh` runs the one migration on every tenant. Smoke check afterwards:
every tenant has the same parent_id values as before the deploy, and none has a loop.
