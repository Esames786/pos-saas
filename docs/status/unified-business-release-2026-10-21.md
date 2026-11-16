# Unified business — release W1/W2 (21 Oct 2026)

Owner's go, 21 Oct: "conditional GO" — only the reviewed W1/W2 fixes; no new screen or "return without a bill" on any
further live tenant; no backfill or repair of old entries; deploy only when every gate below is met, else hold and report.

## What ships

Branch `feat/unified-business-20261021` (worktree `pos-saas-qrwaiter`), on top of production `025728d8`.

| Commit | What |
|---|---|
| `cb039389` | customer returns posted once (form key); expired / unchecked medicine never restocked by itself; return-number clash |
| `f17e01eb` | supplier return never more than its receipt line brought in (two at once); "Post return" posted once; PRET number clash |
| `582f4b70` | read-only readiness in Master; StandardUnits; supplier-return matrix pinned; test isolation fix |
| `dbe9fd64` | system map |
| `c0df44cb` | stock unit fixed once stock / purchases ran in it — every write path; no-bill refund conditions; number clash by value |
| `e7c8e62f` | system map (the commit the final suites ran on) |

Not in this release: supplier bill net of a return (designed separately, see below), W3–W7.

## Migrations (tenant, every tenant)

| Migration | Statement | Rows on the biggest live table |
|---|---|---|
| `2026_10_21_000001_add_submission_key_to_sales_returns` | 2 nullable columns `ALGORITHM=INSTANT`; unique index `ALGORITHM=INPLACE, LOCK=NONE`; `lock_wait_timeout=10` | khatribiryani 294, kashiffood 180 |
| `2026_10_21_000002_add_submission_key_to_purchase_returns` | same | 0 on every tenant |

Production MySQL 8.0.46 (both statements supported). `TOTAL_ROW_VERSIONS` = 0 for both tables on every tenant (the
INSTANT limit is 64). No backfill, no UPDATE. Residual risk: each ALTER needs a moment's metadata lock — if a long
query holds the table, a return waits at most 10 s or the migration fails and deploy reports that tenant (re-run).
The code checks the column before writing it, so a tenant the migration has not reached yet posts as before.

## What changes for live tenants (switches as they are)

- Returns (Khatri 294, Kashif Food 180 in 90 days): a double click / retry no longer posts a second return. A tab
  opened before the release (no key) posts exactly as before.
- Product edit: a unit cannot change once the item's stock or purchases ran in it (the readiness list names such items).
- Master: one more read-only box (Super Admin).
- Nothing else: no feature switch is changed by this release.

## Gates (filled as they pass — never pre-filled)

| Gate | Evidence |
|---|---|
| Full MySQL suite green on the FINAL commit | `e7c8e62f`: 2,318 tests, 1 failure = `SalesAnalyticsMySqlTest` (known 19–24 UTC flake; fails the same on production `025728d8` at the same hour, 22:32 UTC); rerun at 00:00:37 UTC on `e7c8e62f`: OK (16 tests) |
| Default suite (Unit + Feature) — no new failure vs production | 230 tests, 3 failures — the same 3 fail on production `025728d8` (run in a temp worktree): catering sidebar, ESC/POS report payload, rider reassignment |
| Double click / retry / concurrent return — real MySQL, two OS processes | `SalesReturnPostedOnceMySqlTest` (bill form twice at once; no-bill form twice at once; two people one bill), `PurchaseReturnPostedOnceMySqlTest` (one receipt line, two returns at once) — each with "both queued behind the lock" proven |
| Feature-OFF path and old-document readers | no key = posts as before; column missing = posts as before; old supplier-return screen (draft → Post) as before; switch off = no no-bill return (404); a return with no key reads on the list, its page and the sale's return screen, and still counts toward what is left (`MedicalCounterMySqlTest`) |
| Sabotage | 18 guards broken one by one: 16 turned the test red. 2 stayed green because a second guard covers the same case: the sale's lines are locked as well as the sale (removing both locks: red), and the Product model refuses a unit change as well as the screen does (removing the model's: red) |
| Production MySQL version / tables / locking reviewed | MySQL 8.0.46, INSTANT row versions 0, tables ≤ 294 rows (above) |
| Backups of every live tenant taken just before | `tenants:backup --no-storage`, 00:02 UTC 21 Oct: master + 13 tenants OK → `storage/app/private/backups/20261021_000232` (khatribiryani 219.8 MB, kashiffood 319.8 MB) |
| Release commits recorded | this file; merged as `5c49790c` (only these 7 commits on `025728d8`), pushed, origin = HEAD verified |
| Deploy | `bash deploy.sh` 00:03 UTC: HEAD `5c49790c`, migrations OK on 13 / 13 tenants, 0 skipped |
| Post-deploy smoke | tb_diff 0.00, neg_on_disallowed 0, department_negative 0 on every tenant; return forms carry the key; product edits open with their own unit; both columns present everywhere; no error in the log after the deploy |
| Permissions | snapshot before / after: no role lost a permission, no user lost a role; Super Admin gained `central.tenants.readiness` |
| Post-deploy reconciliation (before vs after) | 00:02 vs 00:04 UTC: **identical on all 13 tenants** — books, stock, suppliers, customers, cash/bank (the three old differences unchanged), sales and returns of 7 days, document counts |
| Activation | none — no switch changed; "return without a bill" still pharmacydemo only |

## Post-deploy reconciliation

`ub_reconcile.php` (read-only), run right before and right after: books (tb_diff, 1400, 2100), stock (qty, value, every
balance row against its own ledger, negative where not allowed), suppliers (stored vs ledger, bills due), customers,
cash/bank (stored vs opening + movements), sales and returns of the last 7 days, and document counts. Any difference
must be explained by documents made in between.

Known before this release (21 Oct trial, not caused by it): cash/bank stored balance vs opening + movements —
kashiffood CASH-MAIN 18,840; kashifkitchen CASH-MAIN 502,500; khatribiryani CASH-MAIN 350. They must read the same after.
