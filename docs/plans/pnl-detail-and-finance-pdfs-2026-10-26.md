# P&L detail + finance PDFs (PNL-DETAIL-1 + FINANCE-PDF-DOWNLOADS-1) — 26 Oct 2026

Branch `feat/pnl-detail-finance-pdfs-20261026` (worktree `pos-saas-pnl`), from production `567a7752` (live `fd144412`).
**Display only. Not deployed. Submitted for review.**

## What is asked

**PNL-DETAIL-1** (khatribiryani, 26 Oct). The P&L shows "6000 Expenses 8,67,600" as one line. The client wants:
- the expense categories inside each account;
- once sub-accounts exist (6010, 6020…), those accounts under their parent head, with a subtotal.

**FINANCE-PDF-DOWNLOADS-1** (khatribiryani, 21 Oct):
- a P&L PDF;
- the Trial Balance PDF upgraded to the grouped Dr/Cr-pair layout, with party detail.

**Targets:** the hand-made samples in `Downloads\Bingoo Reports`:
- `Khatri-Biryani-PnL-2026-10-01-to-2026-10-21.pdf`;
- `…PnL-Posted-and-Drafts…` (its category lines only — its Drafts columns and notes were one-off analysis, not system
  text);
- `…Trial-Balance-Party-Detail-2026-10-01-to-2026-10-21.pdf`.

PNL-DETAIL-1's R3 is the P&L PDF part of FINANCE-PDF-DOWNLOADS-1, so both are built here once.

## Impact (system map, 26 Oct)

- **`tenant.finance.profit-loss.index`** (`/finance/profit-loss`, ProfitLossController@index).
  - Today it reads `branches, accounts, journal_lines`.
  - Now, for the detail, it also reads what the Trial Balance's party split reads (`TrialBalancePartyService::partiesFor`):
    - `journal_entries`, `expense_voucher_lines`, `expense_categories`;
    - `suppliers` and the bill / payment / return rows that name them;
    - `cash_bank_accounts` and their transactions.
  - Screen, CSV and the new PDF (`?format=pdf`).
- **`tenant.finance.trial-balance.index`** (`/finance/trial-balance`): same tables; the PDF (`?format=pdf`) layout changes.
  Its party lines change in two ways (below).
- **Not touched:** `tenant.finance.branch-profit-loss.index` (R4: BranchProfitLossService and its numbers as they are).
- **Nothing written:**
  - no migration, no new route, no permission (both PDFs are query parameters on existing routes);
  - `ProfitLossService` totals, the Cost of Sales rule (`AccountSections`) and `trialBalancePeriod()` unchanged.
- **Tests:** new `ProfitLossDetailMySqlTest` and `TrialBalancePdfMySqlTest`. Re-run: `TrialBalancePartyMySqlTest`,
  `TrialBalancePeriodMySqlTest`, `TrialBalanceSectionHeadsMySqlTest`, `GrnNonStockMySqlTest`,
  `InventoryExpensePurchaseStockMySqlTest`.

## The build

**One builder, three outputs.** A new `ProfitLossReport::build($pl, $branchIds, $detail)` turns `statement()` into the
display tree. The screen, the CSV and the PDF all render that one result, so they cannot disagree. No SQL in a view or
in PDF code.

**R1 — grouped P&L.** Each part of the statement goes through the live `TrialBalanceLayout` tree (TB-SECTION-HEADS-1),
so no second grouping is written:
- the parts: revenue rows, sales-discount rows, Cost of Sales rows, operating-expense rows;
- a parent with shown children is a bold head equal to itself plus its descendants;
- its own postings show as "<name> (direct)";
- children are indented.

Each part's rows go in on their own, so Gross Revenue, Less: Sales Discounts, Net Revenue, Total Cost of Sales, Gross
Profit, Total Operating Expenses and Net Profit stay exactly `statement()`'s.

**R2 — detail lines.** "Show detail", default ON (`detail=0` hides it; carried in the query string like the TB's
`parties`):
- **Source:** `partiesFor()` over the Cost of Sales and expense accounts, for the same dates and branches. Each line =
  that party's period debit − credit, so the P&L detail and the TB detail are the same numbers.
- **Where:** under each Cost of Sales and expense account (and each "(direct)" row), as indented grey lines. Never under
  a head.
- **Labels:** expense category ("code · name"), supplier, or "Other".
- **Sums:** lines always add up to the account; a remainder goes on "Other", never dropped.
- **Order:** largest first, "Other" last (catch-alls last, as on the TB).
- **Skipped:** an account whose only line would be "Other" for its whole amount shows no detail — one line repeating the
  account says nothing.
- **Revenue:** no detail in v1.

**R3 — same everywhere.**
- **CSV:** `Section, Row, Level, Code, Account, Detail, Amount`.
  - Row ∈ Head / Account / Detail / Total.
  - A SUM over the Account rows of a section equals that section's total.
- **P&L PDF** (`?format=pdf`, Dompdf A4 portrait, DejaVu Sans, `<thead>` repeated, "Page X of Y"), as the sample:
  - centred business name, "Profit and Loss Statement", "For the period 1 October 2026 to 21 October 2026 · Amounts
    in PKR", plus the branch when filtered;
  - Particulars | Amount; grey monospace codes, `nowrap`; the same tree and detail;
  - the discount line in brackets; bold totals; Gross Profit and Net Profit shaded, a double rule under Net Profit,
    margins in italics;
  - footer "Prepared from Bingoo POS · <date time> · <user>".
  - **Buttons:** "PDF" next to "CSV" on the screen, plus the "Show detail" tick.

**Trial Balance PDF** (existing `?format=pdf`, same route, filters and single `partiesFor()` call), as the sample:
- **Columns:** Code · Account / Party · Opening Dr · Opening Cr · Period Dr · Period Cr · Closing Dr · Closing Cr. The
  Type column is dropped.
- **Two-row heading:** "Opening (<from − 1 day>)", "Transactions <from>–<to>", "Closing Balance (<to>)"; it repeats on
  every page.
- **Grouped (the default):** the six sections of TB-SECTION-HEADS-1, each with "Total <section>" across all six
  columns. `group=0` keeps the flat list in the same columns.
- **Rows:** party lines indented and grey, all six columns. The Grand Total row last, printed from the service's totals
  (rounded once, never a sum of rounded rows).
- **Footer:** "Prepared from Bingoo POS · <date time> · <user> · Trial Balance difference: <difference>", red when it is
  not 0.
- **Signatures:** the signature lines (TRIAL-BALANCE-PERIOD-1 part 2) stay below the footer.

**Shared rules.**
- **File names:** `profit-loss-<from>-to-<to>.pdf`, `trial-balance-<from>-to-<to>.pdf`.
- **Delivery:** `Content-Disposition: attachment` (phones download instead of opening a blank tab).

## Decisions taken here (named for review)

1. **`partiesFor()` names the supplier on bill postings to an expense account.** Today only AP gets suppliers; a bill
   line on an expense account lands in "Unassigned".
   - An expense account that bills post to now gets party lines too: suppliers, plus "Other" for the rest.
   - Both specs ask for "suppliers for bill postings". The TB shows these new lines too; every block still adds up to
     its account.
2. **Catch-all labels.**
   - Cash accounts: "Not linked to a drawer / bank" (spec).
   - Expense accounts: "Other" (the P&L's word; one word for the same thing on both reports).
   - Accounts Payable and bookings keep "Unassigned".
3. **No detail line that only repeats its account** (R2, above).
4. **Order.**
   - Accounts come in chart (code) order — the order of the Trial Balance's tree and of the chart itself. The hand-made
     sample sorted expenses by amount; that would need a second ordering of the tree.
   - Detail lines are largest first, as the spec says.
5. **The Trial Balance PDF's head totals add each column separately** (opening and closing debits and credits not
   netted), as the sample does and as an accountant adds a column. The screen's netted subtotal is unchanged.
6. **A long category list can continue onto the next page,** under the repeated column heading, as in the hand-made
   sample.
7. **Fixture note:** the expense-voucher posting falls back to account 6800 Miscellaneous Expense. A test chart without
   6800 posts no voucher journal at all (its safe catch), so the new test's chart includes it, as every real chart does.

## Acceptance (MySQL, through the controllers)

**P&L**
1. Two voucher categories on 6000 → two detail lines summing to 6000; Net Profit identical to the flat statement.
2. 6000 → 6010, 6020 → 6000 is a head = 6010 + 6020 (+ "(direct)"); the section total is unchanged.
3. A cost account fed by vouchers and a stock-to-cost journal → "Other" for the journal part; lines still sum.
4. `detail=0` hides the lines; heads remain.
5. CSV and PDF carry the same heads, detail and totals as the screen; a branch filter carries into all three.
6. `format=pdf` is `application/pdf` with the attachment name for the Owner, and 403 (route permission) for a role
   without the P&L.

**Trial Balance PDF**
- Section subtotals equal their accounts; the Grand Total is equal in all three pairs; the difference is shown.
- Party lines sum to their account.
- The branch filter carries.
- Attachment.

**Every view:** Blade compiled, the generated PHP linted, and rendered.

**Production after deploy (read-only):** Khatri P&L 1–26 Oct, 6000 and 6200 with their category lines. Both PDFs
download for khatribiryani.
