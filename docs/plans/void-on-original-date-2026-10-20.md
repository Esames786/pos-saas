# VOID-ON-ORIGINAL-DATE-1 — every money void asks "which day should this be undone on?"

Date: 2026-10-20 · Branch: `feat/void-on-original-date-20261020` · Owner: "void jahan jahan hai wahan sawal poochhe — reverse or exact date wipe … tamam portal ke void pe"

## Problem
Khatri's accountant voided six wrong supplier payments (3–14 Oct) on 17 and 20 Oct. Every void dated its money-back
entry on the VOID day, so the 1–16 Oct Cash Book stayed Rs 1,08,169 short although today's balance was right.
In QuickBooks / Xero a *Void* zeroes the entry on its own date; a *Reverse* posts a new entry on a new date. We only
had Reverse. (The six Khatri voids were re-dated by hand on 20 Oct, backup #515.)

## What changes
- `App\Support\VoidDate` — `original` (wipe on the document's own date) | `today` (reverse today). Default `today`, so
  any caller that does not send an answer behaves exactly as before.
- `JournalService::reverse(..., ?string $onDate)` and `JournalPostingService::reverseForSource(..., $dateMode)`.
- Seven money voids take `void_date_mode` and date BOTH the GL reversal and the cash/bank money-back row:

| Void | Route | Original date used |
|---|---|---|
| Supplier payment | `tenant.supplier-payments.void` | original JE date / paid-out cash row date |
| Expense | `tenant.finance.expenses.void` | `expense_payment` cash row date, else payment/expense date; JE's own date |
| Manual journal | `tenant.finance.manual-journals.reverse` | JE `entry_date`; each cash row's own date |
| Catering receipt | `tenant.catering.advances.void` | `customer_payment` cash row date, else `received_date`; JE's own date |
| Whole booking | `tenant.catering.final-invoices.void-everything` | each invoice/application/receipt entry's own date |
| Opening balance | `tenant.finance.opening-balances.void` | `opening_date`; JE's own date |
| Department handover | `tenant.departments.handovers.reverse` | payout JE / reclass JE own dates |

- Screens: one partial `partials/void-date-choice` (two picture cards, nothing pre-picked, Urdu help) in every void
  form/modal — supplier payment, expense, manual journal, catering receipt (both screens), whole-booking (both screens),
  opening balance, Cash Book modal; a compact select on the handover table row.
- Stock / POS voids stay on today (GRN void, sale cancel): back-dating stock rewrites average cost/FEFO and a closed
  shift's counted cash. Their confirm text now says so. (The GRN confirm strings had raw line breaks — the dialog never
  ran — fixed with `\n`.)

## Impact (system map)
Tables written with a chosen date: `journal_entries` (reversal `entry_date`, `entry_no` prefix), `cash_bank_account_transactions`
(`transaction_date`). Unchanged: `supplier_ledgers` (no business date — created_at log), all `voided_at` audit columns
(still the moment of the action), stock tables. No migration, no new route, no new permission.

## Tests
New: `CashBookVoidMySqlTest` (+7: wrong payment wiped on its date and the day nets to 0; right payment today; no answer
= today; unknown answer refused; manual journal; expense; every void screen asks with nothing pre-picked + Cash Book
script parses), `CateringAdvanceVoidMySqlTest` (+1), `CateringVoidButtonMySqlTest` (+1), `DepartmentHandoverTest` (+1,
clock moved 3 days), `OpeningBalanceVoidDateMySqlTest` (2, new). 10 sabotages.
