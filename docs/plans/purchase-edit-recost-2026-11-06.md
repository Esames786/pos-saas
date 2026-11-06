# PURCHASE-EDIT-RECOST-1 — edit / void a purchase whose cost was moved by a recost (6 Nov 2026)

Owner, 6 Nov, after SUPPLIER-COST-5001-1 recosted Khatri's 4 Nov Kashif Kitchen bill and the GRN said
"An earlier cost correction is on its supplier invoice, so it cannot be edited here":
"edit kyun nhi hosakta, objective to ye b tha k edit hosakte" — then "jo objective hai client ko achieve hona chahiye,
her chez edit void back date entry current entry, jo jo last 3 weeks say kam karwae thay wo b mind mai rakho".

## Goal
A receipt + invoice that carries a `purchase_bill_recost` correction can be edited (rate, quantity, dates — also moved
back to an earlier date) and voided (wiped on its own date, or reversed today), exactly like any other purchase, and the
cost is counted once, on the head the recost chose.

## Why it was refused (PURCHASE-EDIT-1, 3 Nov, v1 scope §7 "v2 if asked")
An edit reverses what is in force for the invoice (`purchase_bill` + earlier `purchase_bill_revision`) and posts the
corrected invoice on the heads its lines are booked to (`goods_receipt_lines.cost_account_id`). A recost leaves its own
journal (`purchase_bill_recost`, Dr new head / Cr old head) and points `cost_account_id` at the new head. Left in force,
an edit would count the cost twice on the new head and push the old head below zero (4 Nov bill, rate 1,400 → 1,300 on 66 kg:
5001 7,36,760, 5100 −2,12,080, 5100-3 −1,59,600; the total right, every line wrong). So v1 refused.

## The change
`PurchaseRevisionService`:
- `reinvoice()` — the recost journal of the invoice is in force too: reversed with the rest, on its own date for an edit,
  on the chosen day (VoidDate) for a void. The corrected invoice then posts straight to the recost's head
  (`cost_account_id`, set by the recost since its first version `ef32c8d2`). The revision lists one line:
  "The earlier cost correction on this invoice was undone with it, so its cost is counted once."
- `blockedReason()` — the recost refusal goes. The 22 Oct stock-correction refusal (`grn_line_stock_expense`) STAYS: no
  receipt anywhere with purchase edit ON carries one (Khatri's were cleared by the 4 Nov books restart), and undoing it
  means stock that was written out — a different job, only if asked.

Nothing else changes: no schema, route, permission, screen or switch. Only tenants with `purchase_edit` ON see it
(today khatribiryani). Supplier ledger, payments on the running account, stock: untouched by this change (a recost never
touches them).

## Impact (system map)
- Tables written: `journal_entries` / `journal_lines` (one more reversal per edit/void of a recosted invoice),
  `goods_receipt_revisions.changes` / `reversed_journal_ids`.
- Screens: `tenant.goods-receipts.show` (Edit / Void now offered on a recosted receipt), `.revise`, `.void`;
  Profit & Loss / Trial Balance read the result.
- Readers of `purchase_bill_recost`: only `PurchaseRevisionService` and the recost command (which skips a bill whose own
  journal was reversed — unchanged; see "Not in this work").
- Tests: `tests/MySql/PurchaseRevisionMySqlTest.php` (+2), whole MySQL suite (money path).

## Facts on prod before (6 Nov, read-only)
- khatribiryani: 1 posted receipt (GRN #90 / bill #75, the Kashif Kitchen bill), refused only for the recost; its 11 lines
  all purchase-only, `cost_account_id` = 5001; recost #31942 dated 4 Nov. No other tenant has purchase edit ON.

## Tests
1. Recosted invoice edited (rate) → the recost reversed once on its own date; this invoice's cost: new head = new value,
   old head 0; edited again with the invoice moved back three days → old day 0, new day on the new head; books agree.
2. Recosted invoices voided — one "on its own date", one "today" → each recost reversed on that day; heads net 0;
   supplier back to 0; books agree.
Sabotage: leave the recost out of what is reversed → test 1 red (new head double); put the refusal back → both red.

## Also in this branch: PNL-DETAIL-RECOST-1 (developer note from the business-side session, 6 Nov)
Found on khatribiryani's P&L, 1–6 Nov, "Show detail" (read on prod through `ProfitLossReport::build`, the screen's build):
5001 3,71,680 with no detail; 5100-3 11,780 shown as "Kashif kitchen 1,59,600 · 5100-7 MAYONAISE & SAUCES 11,780 · Other
−1,59,600". Totals right, detail wrong.

Cause: `TrialBalancePartyService` (the P&L detail, the TB party detail and both PDFs) knew only `purchase_bill` /
`purchase_return` (+ reversals) as bills. A recost (`purchase_bill_recost`, source = the bill) and an edit's corrected
journal (`purchase_bill_revision`, source = `goods_receipt_revisions.id`) were neither: an account fed only by them (5001)
was not split at all, and on the old heads their minus fell to "Other" while the bill kept its supplier.

Fix (one file, display only, no entry changes): both types and their `_reversal`s are bill sources; a recost resolves to
`purchase_bills.supplier_id`, a revision through `goods_receipt_revisions.purchase_bill_id` → the bill's supplier. The AP,
cash and expense-voucher branches and the catch-all labels are unchanged.

Test (`PurchaseRevisionMySqlTest`, the note's shape): one supplier's bill over two old heads (5100 and 5170) plus an item that
stays on 5100, another supplier staying on 5170; recost both moved items to 5160 → 5160 = the supplier once (1,500), 5100 =
the supplier for what stayed (200), 5170 = the other supplier only (150), no "Other"; Cost of Sales and Gross Profit
unchanged; after a GRN edit 5160 = 1,400 under the supplier. Sabotage: recost taken off the list → 5160 detail empty
(the live 5001 symptom) RED; revision lookup off → after the edit 5160 detail empty RED. P&L / TB files green on the fix:
ProfitLossDetail 9, TrialBalanceParty 12, TrialBalancePeriod 13, TrialBalanceSectionHeads 9, GrnNonStock 17,
PayablesFinanceGate 6. The test store's plan has no finance screen (403), so the test reads the screen's build; the screen
itself is covered by ProfitLossDetailMySqlTest.

Acceptance after deploy, khatribiryani 1–6 Nov "Show detail": 5001 → one line, Kashif kitchen 3,71,680; 5100-3 → one line,
5100-7 · MAYONAISE & SAUCES 11,780; no Kashif kitchen / "Other" pair anywhere; Cost of Sales and Gross Profit as before; tb 0.

## Not in this work
- Receipts with the 22 Oct stock correction (none exist with edit ON).
- A bill edited AFTER posting is skipped by `finance:recost-nonstock-bills` (its `purchase_bill` journal is reversed) —
  as since PURCHASE-EDIT-1. An edited bill keeps the heads it was booked to; a later re-mapping needs the edit page or a journal.
