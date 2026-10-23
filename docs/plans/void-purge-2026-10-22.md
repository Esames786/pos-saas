# VOID-PURGE-1 — permanently remove an entry that was voided on its own date (22 Oct 2026)

Requested by kashifkitchen: a wrong entry should be gone "as if it never happened", not shown as a voided row plus
its reversal. Spec: Claude doc "VOID-PURGE-1" (BGXDpjZhSaDvxrcwzLFLqq). The user's go (22 Oct): "option dedena void
kardena permanently remove wala". Branch `feat/void-purge-20261022` off production `59787ff6`.

## The rule

- **Only an entry that is already voided** can be removed, and only when the void was dated the entry's own date
  ("wipe it on its own date", `VoidDate::ORIGINAL`). The entry and its reversal go together, so every total stays
  exactly where the void left it.
- **A live entry cannot be removed.** Void it on its own date first.
- **v1 types:** manual journal, expense voucher, supplier payment, catering receipt (advance).
- **Refused with a plain message:**
  - a "reverse today" pair dated on two different days (deleting it would move every day's closing between them);
  - a catering receipt applied to a final invoice or settlement, or on a booking that has a final invoice;
  - a system cash-shortage voucher (`EXP-SHORT-…`; closing the shift again would recreate it);
  - a supplier payment that still has a credit allocation;
  - anything else.
- **A hidden copy** of every removed row goes to `purged_entries` (JSON snapshot: who, when, why, from where). It is
  shown only on an Owner-only "Deleted entries" log, read-only, with no restore in v1.
- **Numbers are never reused.** Journal numbers (`JE-date-####`), manual journal numbers and expense voucher numbers
  are "highest + 1". Each removed number is kept in `purged_numbers`, and those three generators skip it.
- **Who:** a new permission `tenant.finance.voided-entries.purge`. `deploy.sh` gives new permissions to the Owner
  only. The user must also type their password and a reason (5 characters or more).
- **Switch:** a per-tenant Master switch "Delete voided entries permanently", **OFF by default**. It is turned on for
  kashifkitchen only, after deploy, on the owner's word (the live tenants OFF rule).

## What a removal takes out

| Type | Rows (original + reversal) |
|---|---|
| Manual journal | both `journal_entries` and their `journal_lines`; the cash rows (`manual_journal` and their `manual_journal_reversal` rows, linked by the original cash row's id); the `supplier_ledgers` rows mirrored from AP lines (`journal_adjustment` / `journal_reversal`) |
| Expense voucher | the voucher and its lines; its GL entry and `expense_voucher_reversal` (if posted — that posting is fail-soft); the cash rows `expense_payment` and `expense_void_reversal` |
| Supplier payment | the payment; GL `supplier_payment` and `supplier_payment_reversal`; supplier ledger `payment` and `payment_void`; cash `supplier_payment` and `supplier_payment_void_reversal`. Allocations were already removed by the void. |
| Catering receipt | the advance row; GL `catering_advance` and `catering_advance_reversal`; cash `customer_payment` and `catering_advance_void_reversal`; its "advance received" email log |

Rows are found by their real keys: `source_type`/`source_id`, `reference_type`/`reference_id`, `reversed_entry_id`
and `void_journal_entry_id`. Never by amount or date.

**Before anything is deleted, checks that must hold:**
- every GL account nets to zero across the pair;
- each cash and supplier-ledger original has its reversal;
- no other table points at the journal entries.

**Stored running balances.** `balance_after` on cash rows (per account) and supplier-ledger rows (per supplier) is
written in insert order. Only rows inserted between an original and its reversal carried the original's effect, so
they shift by exactly that amount. Rows after the reversal are unchanged, because the pair nets to zero.
`current_balance` on accounts and suppliers is never touched, and is asserted unchanged. There is no full rebuild:
kashifkitchen's stored cash balance already sits 5,02,500 above its rows, and a rebuild would change unrelated numbers.

## Impact (system map, 22 Oct)

- **Tables written:**
  - deleted from: `journal_entries`, `journal_lines`, `cash_bank_account_transactions`, `supplier_ledgers`,
    `supplier_payments`, `expense_vouchers`, `expense_voucher_lines`, `catering_advances`, `catering_email_logs`;
  - `balance_after` updated on `cash_bank_account_transactions` and `supplier_ledgers` (only rows between a pair);
  - **new:** `purged_entries`, `purged_numbers`. The migration is additive. Code refuses to purge until the tables
    exist (schema first).
- **Screens that change:** Cash Book (a "Delete permanently" button on voided rows); the supplier payment, expense,
  manual journal and catering event pages (the same button); a new Owner-only list "Deleted entries" under Finance.
- **Screens that read the rows:** journal list, GL, TB, P&L, Cash Book, supplier ledger, supplier payments, expenses,
  manual journals, catering event and balances. They simply no longer see the pair.
- **Number generators:** `JournalService::nextEntryNo`, `ManualJournalController::nextManualJournalId`,
  `ExpenseVoucherController::nextVoucherNo` also skip `purged_numbers`.
- **`tenant:reset-transactions`:** `purged_numbers` is wiped (numbering restarts with the transactions).
  `purged_entries` is kept: the Owner's audit trail survives a reset.
- **Tests:** new `VoidPurgeMySqlTest`, through the real routes:
  - each of the 4 types: removed completely; snapshot complete; totals and balances unchanged; a row between is
    corrected;
  - every refusal writes nothing;
  - a forced failure halfway rolls back everything;
  - numbers are not reused;
  - Cash Book and the log show the result.

  Re-run: Cash Book void, catering advance void, manual journal guard, supplier finance, the reset classification.

## Risk the owner accepts by switching it on

An Owner can make a recorded cash movement disappear from every screen. The only trace is the Owner-only log with
the hidden copy. This must be stated to the owner before the switch is turned on.

## Owner's decision (22 Oct, after the build)

- Switch **ON for kashifkitchen and khatribiryani**. Every other tenant stays OFF.
- Who may delete, besides the Owner role (which deploy.sh gives both permissions):
  - kashifkitchen: one non-Owner user, granted on the user only, so the other users with the same role do not get it;
  - khatribiryani: the Accounts role (every accounts user).
- Users who may delete also get the "Deleted entries" list, because the screen goes there after a delete.
- kashifkitchen's existing voids: the client asked that voided entries do not show anywhere. A dry run lists which
  ones qualify (voided on their own date) and which stay, with the reason. They are removed only after the owner has
  seen that list. khatribiryani: access only, nothing removed.

## Owner's decision (23 Oct): voids dated another day may be deleted too; the answer comes as a popup

- "Kholdo option permanent delete ka, koi masla nahi jab sab kuch permission based kar diya hai." A pair whose void is
  dated another day ("reverse today") can now be deleted as well. Before anything is deleted, the person is told which
  days' cash closing changes and by how much (VoidPurgeNeedsConfirmation), and must answer "Yes, delete". Those days then
  read as if the entry had never been made; every total today stays the same (the pair still nets to zero per account,
  and the running balances in between are shifted exactly as before).
- Still refused: a live entry; a settlement / final-invoice receipt; a cash-shortage voucher; a payment with an
  allocation; anything that does not net to zero or that another record points at.
- The confirmation form is sent in the background. A refusal or the "Delete anyway?" question comes back as a popup
  (SweetAlert) without reloading the page; a wrong password or missing reason reopens the form. Found because a client
  pressed Delete twice on JE-20261001-0012 (voided 11 Oct) and did not see the refusal at the top of the Cash Book.
