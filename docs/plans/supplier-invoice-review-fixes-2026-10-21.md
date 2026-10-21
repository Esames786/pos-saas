# Supplier Invoice — review fixes before release (21 Oct 2026)

Review of `31e239ac` (desktop/mobile screenshots, code, saved logs): the screen is good, but three gaps block release,
and the purchasing UI must read in clear English with one set of business terms. Nothing here is deployed or
switched on; the unified branch first took production `b22aad1e` (merge `704c572c`).

## Gaps (bill posting)

1. **Accounting failure must not look like success.** Today the bill, its supplier-ledger row and the credit moves
   commit first; the GL journal posts after, and a failed journal returns null while the screen says "posted".
   Fix: the journal (and every netting journal) posts INSIDE the same transaction; a journal that does not post
   throws, so the bill, lines, ledger rows, nettings, credit moves and audit rows all roll back. The screen shows the
   reason with the form as typed; posting again works once the cause is fixed. Test: a real failure (the payable
   account switched off), not a mock.
2. **A blocked net invoice must not push the user to a full invoice.** When the return's credit already paid a now
   settled bill, "Net invoice" stays blocked (owner: a settled bill is never reopened here). The message no longer
   says "choose Full invoice": it says choose Full only if the supplier's invoice really lists everything; if the
   supplier deducted the return, leave the receipt unbilled (it stays in the list) and have the accountant resolve the
   settled bill first. An authorized "reopen a settled bill" path is an owner decision — not built.
3. **Partial deduction.** Return 200, supplier took 100 off this invoice. The net option now asks, per return,
   "Deducted on this invoice" (filled with the full amount; 0 < x ≤ the return). The netting row, the supplier-ledger
   debit and the GL mirror (scaled by value, as the return was split) use x. The return's unused credit is spent
   first, then — only if needed — credit is moved off other bills (open ones, newest first; a settled one blocks).
   The rest of the return stays an ordinary supplier credit. The old plan note "partial → discount field" is wrong
   and removed: a return credit is never recorded as a discount.

## UI

- Clear English on every purchasing screen and in the messages behind them: "Full invoice — before returns",
  "Net invoice — returns already deducted", "Supplier credit applied", credit-move warnings in English. Roman Urdu
  removed from labels, buttons, hints, validation and confirmation text. Urdu product names and chosen Urdu/bilingual
  prints untouched. (Small Urdu-script helper lines stay for now — the owner's rule "Urdu help under English" — and
  are listed for a decision.)
- One set of terms: Purchase Order (optional), Goods Receipt, Supplier Invoice, Supplier Return, Supplier Credit,
  Payment — sidebar, purchasing tabs, page titles.
- "How to use this screen": one collapsible panel (closed by default), the same on each purchasing task screen —
  what the screen does, the steps, what happens to stock / supplier balance / payments, a worked example, why an
  action may be blocked. Short hints beside the fields that need them.
- Supplier Invoice on a phone: a bar fixed at the bottom with the balance due and "Review invoice".

## Impact (system map)

Tables written by posting (unchanged set): `purchase_bills`, `purchase_bill_lines`, `supplier_ledgers`,
`supplier_credit_allocations`, `purchase_bill_return_nettings`, `supplier_credit_reallocations`, `journal_entries`,
`journal_lines`, `suppliers.current_balance`. No migration. Screens: Supplier Invoice create (new + old route),
purchasing tabs, sidebar, Goods Receipt create, Purchase Order create, Supplier Payment create, Supplier Return create,
Supplier form. Tests: `SupplierNetBillMySqlTest`, a purchasing "plain English" render test over normal / validation /
blocked / confirmation states, `GrnChargesVisibleMySqlTest`, `GrnBillChargesMySqlTest`, purchasing suites.

Out of this step: one simple-purchase workspace and a shorter sidebar (W4); units/packs end to end (W2 completion).
