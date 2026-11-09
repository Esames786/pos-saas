# CHART-SEEDER-KEEP-NAMES-1 — a deploy no longer renames a tenant's built-in accounts (9 Nov 2026)

Source: developer note from the business-side session (KHATRI-STOCK-LEFTOVER-1 + CHART-SEEDER-KEEP-NAMES-1, 9 Nov). Owner:
"teekh hai kardo, apply fix". Part A of that note (Khatri purchases in 1400) was already run the same afternoon — see
`docs/plans/no-stock-switch-2026-11-09.md` / memory; this is Part B.

## Problem
`deploy.sh` step [5/9] runs `DefaultChartOfAccountsSeeder::run()` on every tenant on every deploy. It used
`Account::updateOrCreate(['code' => …], [name, type, normal_balance, parent_id, is_system, is_active, sort_order])`, so each
deploy wrote every built-in head's default name, parent and sort order back. Khatri renamed 6500 "Printing & Stationery
Expense", 6900 "1% Expense", 6910 "Owner Drawing"; the 9 Nov 06:21 UTC deploy turned them back; the client renamed them again
at 06:49–06:50 UTC. Entries never moved (they sit on account ids), only the P&L / Trial Balance labels. It also undoes any
re-parenting (KHATRI-ACCOUNTS-CLEANUP-1 would be lost on the next deploy).

## Change (one file)
`database/seeders/Tenant/DefaultChartOfAccountsSeeder.php`:
- code MISSING → created with every default, as before (new tenant, new built-in code, an unused head that was removed);
- code EXISTS → the tenant's name, parent and sort order stay; type, normal balance, `is_system` and `is_active` are still
  held. Deviation from the note (which left `is_active` alone too): `JournalService::accountIdByCode()` refuses an inactive
  account, and the account edit form lets a user untick "active" on a system head — the deploy re-activating it is what keeps a
  slip from stopping sales or closings. Hiding a head is `accounts.is_hidden` (FINANCE-RESTART-1), untouched here.
- Renaming a built-in head for every tenant is now a deliberate migration or command, never this seeder (class doc says so).
No app code looks an account up by name (lookups are by code or id). `deploy.sh` unchanged: it pulls in step [1/9], before the
seeder in [5/9], so the deploy that ships this already keeps today's names.

## Impact (system map)
Table `accounts` on every tenant at every deploy (fewer writes than before). Screens that show the names: Chart of Accounts,
P&L, Trial Balance, Balance Sheet, account pickers. Callers of the seeder: `deploy.sh`, `TenantProvisioner` (new tenant — create
path unchanged), demo seeders, ~40 MySQL test files.

## Tests
`tests/MySql/ChartSeederKeepsTenantNamesMySqlTest.php` (3): a re-run keeps renamed 6500 / 6910 (+ sort order) and 6900 moved under
5000; a head switched off with its type changed comes back active, expense, debit, system; a fresh chart is complete and a removed
unused head comes back with its default name, parent and order. Sabotage: the old seeder → the first test red. Full MySQL suite
on the frozen commit.

## Acceptance after deploy
Khatri 6500 / 6900 / 6910 keep "Printing & Stationery Expense" / "1% Expense" / "Owner Drawing" (parent and order as the client
set them); every tenant's account count unchanged; tb_diff 0 everywhere; no role lost a permission.
