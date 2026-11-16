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

## Results (26–27 Oct, local)

- **Frozen run on `6f68a12c`** (`pos-saas-verify-upv`, DB set `_relz`, clean tree before and after): **95/95.**
  - New: `ProfitLossDetailMySqlTest` (9) and the TB PDF tests in `TrialBalancePartyMySqlTest`.
  - Re-run: `TrialBalancePartyMySqlTest`, `TrialBalancePeriodMySqlTest`, `TrialBalanceSectionHeadsMySqlTest`,
    `GrnNonStockMySqlTest`, `InventoryExpensePurchaseStockMySqlTest`, `GeneralLedgerTotalsMySqlTest`,
    `PayablesFinanceGateMySqlTest`, `SupplierCreditClarityMySqlTest`.
- **Sabotage** (each guard broken, then restored; checksums verified):

  | Guard broken | Caught by |
  |---|---|
  | The remainder line "Other" | "the lines still make 3,400" |
  | The supplier on a bill posting | "a bill names its supplier" |
  | The attachment header | Both PDF download tests (P&L and TB) |
  | Head totals counting the head rows | 12,900 instead of 4,500 |

  For the last one, the sabotage showed that no test covered nested heads in the PDF total. A test was added
  (`TrialBalanceSectionHeadsMySqlTest::test_the_pdf_head_totals_count_each_account_once_under_nested_heads`); it passes
  on the code and fails on the sabotage.
- **Both PDFs rendered from a Khatri-like local chart**, through the controllers (1–21 Oct), and read page by page.
  - **The P&L** matches the hand-made sample's figures exactly: Gross Revenue 8,440,070, Net Revenue 8,435,533, Cost of
    Sales 615,775, Gross Profit 7,819,758, margin 92.7%.
    - Category lines appear under 6000 / 6100 / 6200 / 6300, "Other" under 6300, and the supplier under 6500.
  - **The TB:**
    - two-row heading, Debit / Credit pairs;
    - section totals, and a Grand Total balanced in all three pairs;
    - difference 0.00 in the footer;
    - "Not linked to a drawer / bank" under the cash account.
  - **Fixed after the first look:** the TB's account column was squeezed (names wrapping onto three lines); it now has
    fixed column widths. The P&L's "Particulars" heading used the code font.
- Every changed Blade view compiled, the generated PHP linted, and each one rendered (the tests render the screen,
  both prints and both PDFs).

## Deployed (26 Oct 2026, 21:01 UTC) — owner: "deploy karo"

**Release.** Merge `f311cb38` (tree identical to the tested branch head `0c97fb10`).
- Backup `20261026_205924`, COVERAGE OK 13/13.
- `deploy.sh`: tenants ok = 13, DEPLOY COMPLETE.

**After the deploy** (read-only):
- **Books and data:**
  - smoke clean 13/13;
  - `PERMISSIONS OK` (nothing gained or lost — no new route);
  - no migration;
  - `RECONCILIATION OK` (no unexplained difference).
- **Screens and PDFs**, opened as the Owner (GET only) on every tenant with Finance:
  - the P&L screen (PDF button and "Show detail" present), the P&L PDF and the TB PDF;
  - status 200, `application/pdf`, `attachment`, `%PDF`;
  - the 4 tenants without Finance answer 403, as before.
- **Khatri, 1–26 Oct:**
  - **6000's own postings = 8,67,600, with its 11 category lines adding up exactly:** Social Media 3,50,840; staff food
    (2nd category) 1,59,110; Staff Food 1,53,290; 1% 84,000; Raita 79,100; Cleaning 12,950; DMC 11,000; Friday 6,920;
    Charity 4,390; TMC 4,000; Police 2,000.
  - **6200 = 8,83,260, with 4 lines:** Daily wages 7,05,980; Commission 1,23,730; K-Meter 50,550; Bilal Danish 3,000.
  - Net Profit 6,78,257.54 = statement().
- **How 6000 reads on Khatri.** Khatri's chart files other expense accounts under 6000 "Expenses", so 6000 is a head
  (23,42,746 = itself + those children). Its own 8,67,600 shows as "Expenses (direct)" with the 11 lines. This is R1
  working on their chart.
- **Probe note.** Running the probe in tinker printed "DateTime::modify(): Failed to parse time string (date_from)" once
  per tenant. That is the TB route's existing rule `after_or_equal:date_from`, which first tries the field name as a
  date; the validator catches the warning. Under FPM it is silent: 0 log entries, every page 200. It predates this work.
