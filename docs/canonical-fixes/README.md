# Canonical fixes prepared from the Edge programme — FOR CODE REVIEW ONLY

Nothing here has been applied to the canonical production branch (`feat/14d-2-plan-upgrade-requests`) or to any production
checkout. Each patch is built against canonical's own file content and checked with `git apply --cached --check` on a temporary
index of the named canonical head, so nothing in a worktree was touched.

## 0001 — `print_prepares` classified for the transaction reset

| item | value |
|---|---|
| file | `0001-print_prepares-reset-classification.patch` (one line in `app/Console/Commands/TenantResetTransactionsCommand.php`) |
| canonical base | `5c74107f` (applies cleanly; verified 16 Nov 2026) |
| defect | canonical `2b054814` (PRINT-PREPARE-1) created `print_prepares` without classifying it; the reset's fail-closed guard therefore refuses to run: "Reset stopped before anything was deleted. These tables are not classified as wipe or keep: print_prepares." The fail-closed behaviour is correct and is kept. |
| fix | `print_prepares` added to `WIPE_TABLES` next to `print_jobs`: an agent-side PDF preparation cache (cascade FK to `printers`, rows cleaned daily by `AutocloseStuckPrintJobsCommand`), transactional like the print jobs beside it; no master data lives in it |
| alternative considered | KEEP (`KNOWN_KEPT`) — rejected: a prepared PDF of a wiped order would survive its order |
| proof | on the Edge tree (same change, commit `25fbb425`): `TenantResetFailClosedMySqlTest` 3/3, `SupplierCreditClarityMySqlTest` and `SupplierNetBillMySqlTest` reset tests green (41 tests, 318 assertions in the re-run of the three classes); the full canonical merged-area gate had failed 4/330 on exactly this cause before the change |
| risk | none at runtime: the command only runs when an operator invokes the tenant transaction reset; the change widens the wipe list by one transient table |
| review ask | confirm WIPE over KEEP, then apply with `git am` (or `git apply`) on canonical and run `TenantResetFailClosedMySqlTest` there |
| execution | NEVER run the transaction reset on LAB or production as part of this (owner rule) |

How to apply when approved (canonical worktree, on a branch):

```
git apply --check docs/canonical-fixes/0001-print_prepares-reset-classification.patch
git apply        docs/canonical-fixes/0001-print_prepares-reset-classification.patch
php vendor/bin/phpunit -c phpunit.mysql.xml --filter TenantResetFailClosedMySqlTest
```
