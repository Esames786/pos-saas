# FINANCE-RESTART-1 — start a business's books again from its POS sales (4 Nov 2026)

Branch `feat/finance-restart-keep-sales-20261104`, worktree `pos-saas-finrestart`, from prod `d4d13707`.
Owner approved on 3–4 Nov ("teekh hai start karo"; "client wapsi say sub wapsi posting kare ga").

## Why

A live restaurant tenant has only been using the POS: sales, and the shift closing's short-cash vouchers (drafts). Its
purchases, expenses, supplier payments and opening balances were entered inconsistently (many expenses were typed by
re-splitting the closing's short-cash vouchers). The owner wants a clean start: **keep every POS sale and the closing
short-cash drafts, cash/card balances from the sales; remove every other finance / purchase / expense / stock entry,
the business's own account heads and the opening balances; keep only the system heads the business used.** Everything
removed goes into one Excel file first, and a DB backup is taken.

## What is built

1. `finance:export-tenant {tenant} [--out=]` — read-only; one .xlsx, a tab each: Summary, Chart of Accounts, Expenses,
   Expense Categories, GRN, Supplier Invoices, Invoice Lines, Supplier Payments, Supplier Ledger, Suppliers, Journal
   Entries (all but sales), Opening Balances, Stock Ledger, Cash Book (non-sale), Short Cash (closing).
2. `finance:restart-keep-sales {tenant} [--yes]` — dry run by default; one transaction:
   - keeps journals `sales_%`, cash/bank movements `sales_%`, every sales / KOT / shift row;
   - every closing voucher `EXP-SHORT-…` (not voided) goes back to what the closing made: one "Daily Closing — Short
     Cash" line, the amount its note says the drawer was short, Draft;
   - removes every other journal, cash/bank movement and expense; purchasing, supplier account, stock / kitchen /
     department movements, opening balances, void-purge copies; suppliers to balance 0;
   - rebuilds each cash/bank account's running balance and balance (opening 0);
   - removes the business's own heads nothing kept uses (and its own expense categories; product categories fall back
     to the default cost head; a system category on a removed head moves to its system parent);
   - refuses when catering / customer-account / manufacturing records exist, or when a sale journal touches a head that
     is not cash/bank or income (sales that move stock);
   - proves before commit: sales identical (counts, totals, sale journals, sale cash rows), trial balance balances,
     nothing left pointing at a removed row.

## Impact (system map)

Tables written: accounts (own heads deleted), journal_entries / journal_lines (non-sale deleted),
cash_bank_account_transactions (non-sale deleted, balance_after rebuilt), cash_bank_accounts (balances), expense_vouchers /
lines, expense_categories (own deleted), categories.cogs_account_id, suppliers (balances), and the purchasing / stock /
opening / purged tables listed in the command (deleted). Sales tables: read only (fingerprint). No screen changes. Only ever run by hand, per tenant.

## Proof

- Local copy of the tenant's real data (backup 20261103_183454): export 15 tabs (XML valid); dry run and `--yes`: sales
  identical (30,094 orders / 3,07,10,314.50), TB balanced, nothing dangling; 192 closing vouchers back to draft (101 had
  been re-split / posted), 30 own heads + 41 own categories removed; a second run changes nothing.
- `FinanceRestartKeepSalesMySqlTest` 2/2 (refusal writes nothing; full restart on a store with a cash sale, a purchase +
  invoice + payment, a re-split posted closing voucher, an own head + category; re-run). Sabotage 2/2 RED.
- Found, not changed: Cash Book vs GL differ by 7,350 on the main drawer after the restart (four old sales returns have a
  cash refund row but no journal) and 130 on Undeposited Funds — pre-existing in the sales, untouched by design.

## Run (owner's word, after the day's last shift closes)

One code deploy (no migration). Then: `finance:export-tenant` → manual tenant backup → dry run (read it) →
`--yes` → smoke (tb 0, sites 200) → hand the Excel to the owner.

## Done on the first tenant (3 Nov 2026 ~20:07 UTC = 4 Nov 01:07 PKT, owner's word, no shift open)

- Deployed `80b87afa` (two commands; no schema / route / screen change): tb_diff 0, sites 200.
- `finance:export-tenant` → `storage/app/private/exports/khatribiryani-finance-20261103_200642.xlsx` (15 tabs, 268 KB;
  copy handed to the owner).
- Manual tenant backup #694 (271 MB, `backups/tenants/khatribiryani/20261103_200648_khatribiryani.sql`), plus the platform
  backup 20261103_183454.
- Dry run read with the owner, then `--yes`: 530 non-sale journals (1,888 lines), 227 non-sale cash movements, 78 GRN,
  74 invoices, 107 payments, supplier ledger + allocations, stock, opening balances, 10 other expenses, 30 own heads,
  41 own categories removed; **194 closing short-cash vouchers back to draft (12,644,399.50)** — 101 of them had been
  re-split into expenses and posted. Sales identical (30,101 orders, 30,716,064.50; 30,445 sale journals); Main Cash
  Drawer 30,270,393.50, Main Bank 120,068.00 from the sales. TB balanced; a second dry run removes nothing.
- Left as found (pre-existing in sales): Cash Book vs GL 7,350 on the drawer (4 old returns: refund row, no journal),
  130 on Undeposited Funds.
