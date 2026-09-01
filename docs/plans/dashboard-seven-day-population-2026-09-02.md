# Two order counts on one dashboard — research

**Status:** RESEARCH ONLY. Nothing built, nothing deployed.
**Date:** 2026-09-02
**Reported by:** the client, from Kashif Food's dashboard — *"Orders Today 295, but the 7-day table
says 291 for the same day."*

---

## 1. The complaint, and what is actually wrong

The client noticed a **count** mismatch and assumed the money was fine, because both figures read
`489,406.00` at the moment they looked.

The money was fine **by accident that day**. The same root cause moves the money on any day with a
partial return — and it did, twice in the preceding week.

---

## 2. Three places compute "sales", and one of them disagrees

| | Statuses counted | Returns subtracted? |
|---|---|---|
| **Report Center** — `SalesReportEngine` | `POPULATION` = `paid`, `partially_returned`, `returned` | yes — `net_sales = grand_total − returns.grand_total` |
| **Dashboard tile** — `SalesReportService::todayStats()` | the same three | yes — `net = billed − returns.grand_total` |
| **Dashboard 7-day table** — an inline query in `DashboardController` | **`paid` only** | **no** |

```php
// SalesReportEngine.php:30 — the canonical definition
public const POPULATION = ['paid', 'partially_returned', 'returned'];

// SalesReportService.php:181 — the tile agrees with it
->whereIn('status', ['paid', 'partially_returned', 'returned'])

// DashboardController.php:185 — the odd one out
->where('status', 'paid')
->selectRaw("… COALESCE(SUM(grand_total), 0) as net_sales, COUNT(*) as orders")
```

So a bill that was returned — wholly or partly — **counts in the tile and in every report, and
vanishes entirely from the 7-day table**. With a partial return, the money that was genuinely kept
vanishes with it.

The column is also labelled **"Net Sales"** while being net of nothing. It is a gross paid sum.

---

## 3. Evidence from production (Kashif Food, read-only)

Recomputed with the same arithmetic each screen uses, including
`sales_returns.grand_total` — the column both the engine and `todayStats` subtract:

```
DIN          | TILE cnt   TILE net       | 7DAY cnt   7DAY net       | difference
-------------|-----------------------------------------------------|-------------------
2026-08-30   | 381        814,327.00     | 377        812,927.00     | 4 orders / 1,400.00
2026-08-31   | 285        463,120.00     | 279        460,630.00     | 6 orders / 2,490.00
2026-09-01   | 360        576,291.00     | 356        576,291.00     | 4 orders /     0.00
```

**Why 1 September looked harmless.** All four of that day's returns were *full* returns, so
subtracting the refunds removed exactly the same money the 7-day table had already excluded by
dropping those orders. The two arrived at the same figure from opposite directions.

The four bills:

```
HS-20260901093608-339   returned     740.00
HS-20260901121920-110   returned      80.00
HS-20260901124053-343   returned   1,050.00
SO-20260901152508-959   returned     780.00
```

**Why 30 and 31 August did not.** Those days carried **partially returned** bills —
3,600 · 820 · 1,000 · 2,440. A partial return leaves real money on the bill: the tile keeps it, the
7-day table throws the whole bill away. Hence 1,400 and 2,490 of real, kept revenue missing from
the history.

The client's screenshot showed 295 vs 291 mid-afternoon; by close of business the same day it was
360 vs 356. **The same four bills, all day.**

---

## 4. Which figure is right

**The tile.** A return does not un-sell a bill — the sale happened, the kitchen cooked, the counter
took the money and later gave some or all of it back. That is why `POPULATION` includes all three
statuses, and why every report in the system nets the refunds off rather than deleting the order.

The 7-day table is the only place in the codebase that answers the question differently, and it does
so because it was written as **a second, hand-rolled query** instead of asking the authority that
already existed. This is the same failure the kitchen sheet had against the quotation, and the same
one the deal identity had against the report: two arithmetics for one truth, and they drift.

---

## 5. Proposed fix

Give the 7-day table the same arithmetic the tile uses, from one place.

```
DashboardController: last7Days
  ├── statuses  →  SalesReportEngine::POPULATION      (not 'paid')
  └── net_sales →  SUM(grand_total) − posted returns for that business day
```

Two candidate shapes:

| | Approach | Notes |
|---|---|---|
| **A** | Extend `SalesReportService` with a `dailyStats(from, to, branch, scopeUser)` that returns the same shape per day, and have the dashboard call it | One authority, reusable, testable on its own. The tile and the table then cannot drift because they share the code, not just the intent. |
| **B** | Patch the inline query in place — swap the status list and left-join a returns subquery | Smaller diff, but leaves a second arithmetic in the controller that the next change can pull apart again. |

**Recommendation: A.** The whole point is that there stops being a second definition.

Scope and window stay exactly as they are: the same `UserDataScope`, the same branch filter, the
same business-day expression, the same 7-day anchor. Only the population and the returns change.

Also rename the column header, or leave it — see §7.

---

## 6. What this must not disturb

| Risk | Answer |
|---|---|
| The Report Center's figures move | Untouched. This changes one dashboard card, which currently disagrees with it. If anything, they start agreeing. |
| Any sale, ledger or journal changes | Nothing is written. This is a read-only presentation query. |
| The tile changes | Untouched — it is already right. |
| Other tenants see different history | Yes, and deliberately: Khatri's 7-day card will start including returned bills and netting refunds, which is what its Report Center has always said. Worth telling the owner before it lands, not after. |
| The card is invisible to operators anyway | True on Kashif Food — it sits behind `tenant.dashboard.details`, which only the Owner holds. So the audience for this fix is the owner, which is exactly who reported it. |

---

## 7. Two questions for the owner

1. **The column header.** It says **"Net Sales"** and today shows a gross paid sum. Once fixed it
   will genuinely be net. Keep the name (it becomes true), or say "Net Sales (after returns)" so
   the change is visible to whoever reads it tomorrow?
2. **Should the count be labelled?** The tile says "Orders Today"; the table says "Orders". After
   the fix both mean *bills raised, returns included*. Worth a one-line note under the card, or
   leave it?

---

## 8. If approved, the build

1. `SalesReportService::dailyStats()` — one authority, the same statuses and the same return
   subtraction as `todayStats()`, grouped by business day.
2. `DashboardController` calls it; the inline query is deleted.
3. Guard tests, each proven to bite:
   - a day with a **fully returned** bill: the tile and the 7-day row agree on count and money;
   - a day with a **partially returned** bill: they agree, and the kept money is present (this is
     the one that fails today);
   - a day with no returns: nothing changes from what the card shows now;
   - a cancelled and a held bill appear in neither;
   - a scoped operator still sees only their own figures;
   - the Report Center's total for the same window equals the sum of the seven rows.
4. Full suite, then deploy, then the same figures re-read on production and compared against the
   table in §3 — which is the before-picture this document exists to preserve.
