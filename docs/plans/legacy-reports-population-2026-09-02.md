# The same bug, five more times — the legacy report queries

**Status:** RESEARCH ONLY. Nothing built, nothing deployed.
**Date:** 2026-09-02
**Found while answering:** *"partial return baqi jagah to theek chal raha hai — Report Center,
Quick Report, cron emails?"*

**Short answer to that question: yes, all four are correct.** They run on `SalesReportEngine`.
What follows is what turned up beside them.

---

## 1. This bug has been found before, and fixed once

The fix is already written down, in the codebase, in the docblock of the one function that got it:

> ```php
> /**
>  * The reporting population — MUST match SalesReportEngine.
>  *
>  * This filtered to status = 'paid' alone, so a returned order vanished from every figure it
>  * fed. At Khatri that hid 8 orders and, with them, the delivery charges the shop legitimately
>  * KEPT on three fully-returned orders: the counter was told to expect 22,500 when the drawer,
>  * the ledger and the shift all said 22,850. A returned order keeps its original sale visible;
>  * the refund is deducted separately.
>  */
> private function baseSalesQuery(array $filters)
> ```

That was a real 350-rupee discrepancy between a report and a cash drawer, chased down and
corrected. **The correction stopped at that one function.** Five sibling queries in the same two
files still filter `status = 'paid'`.

---

## 2. Where it still lives

| Screen | Query | Filter |
|---|---|---|
| `/reports/sales/summary` | `SalesReportService::baseSalesQuery` | ✅ **fixed** — 3 statuses, returns netted |
| Dashboard tile | `todayStats()` | ✅ correct |
| Dashboard 7-day card | `dailyStats()` | ✅ **fixed today** (`718562b`) |
| `/reports/sales/items` | `SalesReportService::items()` | ❌ `paid` only |
| `/reports/sales/payments` | `SalesReportService::payments()` | ❌ `paid` only |
| `/reports/sales/channels` | `baseDeliveryQuery()` | ❌ `paid` only |
| `/reports/sales/riders` | `baseDeliveryQuery()` (same) | ❌ `paid` only |
| Restaurant → Tables | `RestaurantReportService::tables()` | ❌ `paid` only |
| Restaurant → Waiters | `RestaurantReportService::waiters()` | ❌ `paid` only |
| Restaurant → Order Types | `RestaurantReportService::orderTypes()` | ❌ `paid` only |

Everything under Report Center, Quick Report, the nightly cron email, the thermal slip and the CSV
export goes through `SalesReportEngine::POPULATION` and is correct.

---

## 3. What it costs, measured on production

### Bills that no `paid`-only report can see

| | Khatri Biryani | Kashif Food |
|---|---|---|
| Returned / partially returned bills, all time | **93** | 14 |
| Their value | **116,650.00** | 18,900.00 |
| …of which delivery orders | **44 — 63,610.00** | 0 |
| …carrying delivery charges the shop **kept** | **9,076.00** | 0 |
| Bills with a waiter attached | 27 | 2 |
| Payment rows against them | **93 — 116,650.00** | 14 — 18,900.00 |

**The delivery number is the same shape as the original 22,500-vs-22,850 incident, forty-four
times over.** `/reports/sales/channels` and `/reports/sales/riders` share `baseDeliveryQuery()`,
so Khatri's channel commissions and rider tallies are both computed over a population missing 44
delivery orders worth 63,610 — including 9,076 of delivery charges that were never refunded.

### `/reports/sales/payments`

Every payment row on those bills is excluded — **116,650.00 at Khatri, 18,900.00 at Kashif Food**.
That money was taken at the counter. A payments report that omits it cannot be reconciled against a
drawer or a bank statement.

### `/reports/sales/items` — two faults at once

Kashif Food, compared with the Report Center's Items section for the same days:

```
              Report Center ITEMS        /reports/sales/items       difference
30 Aug        qty 1,325   816,250.00     qty 1,615   808,120.00     −290 qty / +8,130 money
31 Aug        qty   813   465,450.00     qty   921   457,330.00     −108 qty / +8,120 money
01 Sep        qty 1,124   585,515.00     qty 1,326   582,865.00     −202 qty / +2,650 money
```

- **Money short** by exactly the returned bills' value — the `paid`-only fault.
- **Quantity over** by 290 / 108 / 202 — a *different* fault: this screen still counts a deal's
  components as separate sales. That is `REPORT-DEAL-COMPONENTS-1`, which the owner had corrected
  on 31 August — **and which also went into the engine only.**

So the Items screen is wrong in two directions at once, and the two errors partly disguise each
other: it shows more things sold for less money.

### Restaurant → Order Types

```
              correct (POPULATION)    /reports/restaurant/order-types
30 Aug        381                     377
31 Aug        285                     279
01 Sep        367                     366
```

---

## 4. Who is reading these screens

Not a dormant corner. On **Khatri**:

```
tenant.reports.sales.items            Owner, Manager
tenant.reports.sales.payments         Owner, Manager
tenant.reports.sales.channels         Owner, Manager
tenant.reports.sales.riders           Owner, Manager, Delivery, Dine In, Takeaway, Quick Sale
tenant.reports.restaurant.waiters     Owner, Manager
tenant.reports.restaurant.tables      Owner, Manager
tenant.reports.restaurant.order-types Owner, Manager
```

On **Kashif Food** the operator roles were cut back on 31 August, so it is Owner — except
`channels` and `riders`, which the owner deliberately granted to Delivery.

**The riders report is the widest-open of the lot** — six roles at Khatri can see it, and it is one
of the two fed by the query missing 44 delivery orders.

---

## 5. Why it keeps happening

`SalesReportEngine` became the canonical authority, but `SalesReportService` and
`RestaurantReportService` kept their own hand-written queries. Every correction since —
the population fix, the deal-components fix, the return-netting fix — has gone into the engine, and
these have quietly stayed behind. Each fix makes the gap wider, and each one is discovered the same
way: someone notices two screens disagreeing.

This is the third time the same shape has appeared:
the quotation against the kitchen sheet, the report against the deal identity, and now the engine
against these six queries.

---

## 6. Proposed fix

### 6.1 The population, everywhere (the correctness part)

Replace the six `where('status', 'paid')` with `whereIn('status', SalesReportEngine::POPULATION)`,
and subtract posted returns where the figure is a money total.

Where each one needs care rather than a blind swap:

| Query | What "correct" means |
|---|---|
| `items()` | population **and** exclude `line_kind = 'component'`, matching `linesBase()` — otherwise the quantity stays wrong |
| `payments()` | population only. Payments are actual receipts; a refund is a separate document and must not be netted off a payments *received* report — it belongs in its own line, exactly as the Report Center's cash & bank section does it |
| `baseDeliveryQuery()` | population. Feeds both channels and riders, so one change fixes both |
| `tables()` / `waiters()` / `orderTypes()` | population, and net the returns for the money columns |

### 6.2 The structural part

Swapping six filters fixes today and guarantees nothing about tomorrow. Two options:

| | Approach | Notes |
|---|---|---|
| **A** | Fix the six queries in place, and add a guard test per screen asserting it agrees with the engine for the same window | Smallest change. The tests are what stop the drift, not the code. |
| **B** | Point these screens at `SalesReportEngine` and delete the duplicate queries | The real cure — but it is a bigger job, and the old screens have columns the engine does not produce (commission per channel, rider phone, table turnaround). Some would have to be added to the engine or kept as a thin join on top of it. |

**Recommendation: A now, B as a separate decision.** A is a small, testable correction to figures
the owner is reading this week. B is a refactor of six screens and should not ride along with a
bug fix — but the guard tests written for A are exactly what makes B safe later.

---

## 7. What must not change

| Risk | Answer |
|---|---|
| Figures the owner knows will move | Yes, deliberately — and upward, toward what the Report Center has always said. Khatri's rider and channel reports will grow by 44 orders / 63,610. **Tell the owner before it deploys, not after.** |
| Anything is written | No. Every one of these is a read-only report query. |
| Report Center changes | Untouched — it is the reference. |
| Commission is recalculated | Channel commission is computed from the orders in view, so it rises with them. That is the correct figure, and it is worth naming explicitly: an aggregator's commission was previously understated on returned orders. |

---

## 8. If approved

1. Six query fixes, each with its own guard test asserting **agreement with `SalesReportEngine`**
   for the same window — that is the assertion that keeps them honest, not a hard-coded number.
2. The Items screen additionally excludes combo components, with the deal-components test restated
   for that screen.
3. Prod before/after recorded for both tenants, the way the deal-identity change was.
4. Full suite, then deploy, then the same figures re-read on production.

## 9. One question for the owner

**Khatri's numbers will move.** Riders and channels by 44 orders and 63,610; payments by 116,650;
items and waiters by smaller amounts. Nothing is being added — this is money that was always
there and that the Report Center has been reporting correctly all along.

Do you want this on both tenants at once, or on Kashif Food first (14 bills / 18,900, much smaller)
so the change can be watched on the quieter one before Khatri sees it?
