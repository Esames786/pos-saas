# Reporting fix: business_date for Returns & Item-Cancellations (2026-08-21)

**Owner:** Mohsin · **Tenant impact:** all tenants (Khatri LIVE) · **Risk:** LOW — additive column + read-only report scoping. No POS/cash/stock/GL behaviour changes.

## 1. Problem

A restaurant runs past midnight. A shift opened before midnight keeps its `business_date` frozen for every sale rung after midnight — this is already correct for **sales/orders**. But **returns** and **item-cancellations** are reported by their raw **calendar date** (`return_date` / `cancelled_at`), because their tables have **no `business_date` column**. So when a return/void happens after midnight while the pre-midnight shift is still open, it lands on the **wrong business day** and the day's numbers never reconcile.

### Concrete case (Khatri, verified read-only)
- Orders 412, 548, 549 — `business_date = 2026-08-19` (sold in shift #16, which opened 08-19 07:15 and closed **08-20 07:18:28**).
- Their returns (450 + 750 + 250 = **1,450**, all cash) were punched **08-20 07:14–07:18**, i.e. *while shift #16 was still open* → they belong to **08-19**.
- Report Center overview for 08-20 wrongly subtracts this 1,450: Billed 141,770 − 1,450 = **Net Sales 140,320**, while shift #17 (08-20) expected cash = 141,770. The 1,450 belongs to **08-19**, not 08-20.

## 2. What is already safe (do NOT touch)
- **Sales / orders** — `COALESCE(business_date, DATE(sale_date))` everywhere (overview, categories, items, waiters, order-types, rider, department, dashboard, shift, CSV, email/scheduled all inherit the engine).
- **Held sale / Add Round / Recall / Review & Pay** — business_date frozen at the opening shift; a check opened before midnight and paid after keeps its original business_date (explicit in `HeldSaleController` + `SalesOrderController`).
- **Order-level business_date** — column present, reports correct.
- **GL / accounting** — return & void journal entries post on their own accounting date (separate axis, correct).

## 3. Root cause
| Table | `business_date`? | Reported by |
|---|---|---|
| `sales_orders` | ✅ yes | `business_date` |
| `sales_returns` | ❌ **no** | `return_date` (calendar) |
| `sales_order_line_cancellations` | ❌ **no** | `cancelled_at` (calendar) |

## 4. Fix design
Add `business_date` to both tables, stamp it at creation from the **originating order's `business_date`**, backfill existing rows from the order, and switch the report queries to `COALESCE(business_date, DATE(<calendar_col>))`.

**Why the order's business_date (not "open shift at punch time"):** `SalesReturnService` already adjusts the *originating order's* shift cash (`$shift = Shift::find($salesOrder->shift_id)` → decrements its `expected_cash`). Anchoring the report to the same order/shift makes the report reconcile with the cash it already moved. A cancelled line likewise belongs to its own order. Both values are already loaded at each creation point.

**Backward-compatible:** the report expression is `COALESCE(business_date, ...)`, so any row left un-backfilled (e.g. an order with a NULL business_date) behaves exactly as today. Zero regression.

**Edge case (accepted):** a return of an order whose shift already closed (returning an old order days later) will book to that old order's business day. Rare; the existing cash logic already only adjusts still-open shifts. Documented, not blocking.

## 5. Single creation authorities (linkage verified)
- `SalesReturn::create` → **only** `app/Services/Sales/SalesReturnService.php:51`
- `SalesOrderLineCancellation::create` → **only** `app/Services/Sales/KotCancellationService.php:265`

Stamping in these two places covers every write path (controllers/Edge delegate to these services).

## 6. Exact change set
1. **Migration** `database/migrations/tenant/2026_08_21_000001_add_business_date_to_returns_and_cancellations.php`
   - `sales_returns`: add `business_date` DATE NULL after `return_date`.
   - `sales_order_line_cancellations`: add `business_date` DATE NULL after `cancelled_at`.
   - Backfill (new column only, derives from existing correct order data — **fixes the 08-20 mis-dated return automatically**):
     - `UPDATE sales_returns r JOIN sales_orders o ON o.id=r.sales_order_id SET r.business_date=o.business_date WHERE r.business_date IS NULL AND o.business_date IS NOT NULL`
     - `UPDATE sales_order_line_cancellations x JOIN sales_orders o ON o.id=x.sales_order_id SET x.business_date=o.business_date WHERE x.business_date IS NULL AND o.business_date IS NOT NULL`
   - `down()`: drop both columns.
2. **Model** `SalesReturn.php` — add `business_date` to `$fillable`; cast `'business_date' => 'date'`.
3. **Model** `SalesOrderLineCancellation.php` — add `business_date` to `$fillable`; cast `'business_date' => 'date'`.
4. **`SalesReturnService.php:51`** — add `'business_date' => $salesOrder->business_date,`.
5. **`KotCancellationService.php:265`** — add `'business_date' => $sale->business_date,`.
6. **`SalesReportEngine.php:108-109`** — `DATE(r.return_date)` → `COALESCE(r.business_date, DATE(r.return_date))` (both bounds).
7. **`SalesReportEngine.php:582-583`** — `DATE(x.cancelled_at)` → `COALESCE(x.business_date, DATE(x.cancelled_at))` (both bounds).
8. **`SalesReportService.php:86-92`** — filter + select/group `DATE(sales_returns.return_date)` → `COALESCE(sales_returns.business_date, DATE(sales_returns.return_date))`.
9. **`SalesReportService.php:208-218`** — dashboard "today returns" tile → match `COALESCE(business_date, DATE(return_date))` against the current business date (single + multi-branch paths).

**Not in this change (separate feature):** `purchase_returns` / `PurchaseReportService` — supplier returns, calendar-scoped, low midnight impact. Flagged for a later pass if wanted.

## 7. Tests (MySQL, authoritative)
- `ReturnBusinessDateReportMySqlTest` — a return whose order.business_date = D-1 but return_date = D (created after midnight) is reported under **D-1**, not D; and it reconciles with the sales overview. Backfill path covered (row created without business_date → migration/backfill sets it from the order).
- Cancellation equivalent — a void with cancelled_at = D but order.business_date = D-1 reports under D-1.
- Backward-compat — a return whose order has NULL business_date still reports by return_date (COALESCE fallback).
- Existing guards must stay green: `SalesReportEngineMySqlTest`, `DepartmentSalesOrdersDistinctMySqlTest`, `SalesReturnCreateUxMySqlTest`.

## 8. Deploy protocol (LIVE tenant)
1. Green: fast suite + **full MySQL ×2** (never two PHPUnit on the same MySQL test DB at once).
2. Commit (scoped — no `git add .`), push.
3. **Backup Khatri** before deploy.
4. `bash deploy.sh` → additive migration runs per-tenant → column added + **backfilled** (this is where 08-20's 1,450 return moves to 08-19).
5. Read-only Khatri verify:
   - TB still 0.00; `sales_orders` count unchanged; no stock/journal delta.
   - 08-20 Report Center overview: returns line now excludes the three 08-19 returns → Net Sales rises back toward Billed; 08-19 overview now includes the 1,450.
   - `sales_returns` / `sales_order_line_cancellations` row counts unchanged (only the new column populated).

## 9. Rollback
`down()` drops the columns; reports fall back to calendar dates via COALESCE (i.e. pre-fix behaviour). No data loss — the calendar columns are never modified.

## 10. Data correction for Khatri (today's backdated return)
Handled **by the migration backfill itself** — no hand-editing of rows. It sets `business_date` (a brand-new column) from each return's originating order's `business_date`. Only the new column is written; `grand_total`, `refund_*`, stock, and journals are untouched → **no other Khatri entry is affected**. Post-deploy verification query confirms the three returns (orders 412/548/549) now carry `business_date = 2026-08-19`.
