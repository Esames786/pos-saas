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
| (next) | stock unit fixed once stock / purchases ran in it — every write path; no-bill refund conditions; system map |

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
| Full MySQL suite green on the FINAL commit | |
| Default suite (Unit + Feature) — no new failure vs production | |
| Double click / retry / concurrent return — real MySQL, two OS processes | |
| Feature-OFF path and old-document readers | |
| Production MySQL version / tables / locking reviewed | MySQL 8.0.46, INSTANT row versions 0, tables ≤ 294 rows (above) |
| Backups of every live tenant taken just before | |
| Release commits recorded | this file |
| Post-deploy reconciliation (before vs after) | |

## Post-deploy reconciliation

`ub_reconcile.php` (read-only), run right before and right after: books (tb_diff, 1400, 2100), stock (qty, value, every
balance row against its own ledger, negative where not allowed), suppliers (stored vs ledger, bills due), customers,
cash/bank (stored vs opening + movements), sales and returns of the last 7 days, and document counts. Any difference
must be explained by documents made in between.

Known before this release (21 Oct trial, not caused by it): cash/bank stored balance vs opening + movements —
kashiffood CASH-MAIN 18,840; kashifkitchen CASH-MAIN 502,500; khatribiryani CASH-MAIN 350. They must read the same after.
