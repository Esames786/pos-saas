# SUPPLIER-LEDGER-RANGE-1: dates on the supplier ledger (14 Nov 2026)

## The ask

The owner sent a screenshot of a supplier's ledger at the catering tenant (`/suppliers/{id}/ledger`) and wrote:
"please add date range here with today and yesterday".

He also asked for no Master switch: "abhe sirf date range add karo is screen pe, today yesterday b".

## What changes

The supplier ledger gets the filter row the Supplier Invoices list already has (BILL-DATE-FILTER-1):

- **From / To date boxes.**
- **Today and Yesterday buttons.** The chosen one is highlighted.
- **All dates link.** It returns to every entry.

How the filter behaves:

- **Which date.** The filter uses the date in the Date column, which is the day the entry was typed, in the shop's time.
- **No dates.** The ledger opens exactly as before, with every entry, newest first, 30 to a page.
- **Paging.** Page links keep the chosen dates.
- **Edge cases:**
  - From after To is read the right way round.
  - A date that does not exist is ignored.
- **Time shown in the Date column.** Stored times are UTC. The column showed them raw, five hours behind Karachi; it now
  shows the shop's time. Without this change, an entry typed at 02:00 on the 14th would sit under Today while its Date
  said the 13th.

There is no switch and no new screen. Amounts, balances and stored data do not change.

## Not touched

- **The day-by-day ledger** (switch `supplier_ledger_daily`, on for one tenant only). It already has From / To; it does
  not have Today / Yesterday. The owner said this screen only.
- **The All suppliers ledger.**
- **Supplier payments.**

## Impact (system map, built 14 Nov, plus a read-only probe of production)

- **Table:** `supplier_ledgers`, read only. No schema change.
- **Screen:** `tenant.suppliers.ledger` (`SupplierController@ledger`), switch-off path only.
- **Who sees it:** every tenant except the one with the day-by-day ledger switched on. The catering tenant has 453 ledger
  rows in the last 30 days.
- **Permission:** `tenant.suppliers.ledger`, unchanged. No new route.

## Tests

`tests/MySql/SupplierLedgerDrCrMySqlTest` gains 2 tests, run over real HTTP:

1. **Today, Yesterday and From / To.** Today and Yesterday each show only that day, in Karachi time. An entry typed at
   02:00 PKT, stored as the previous day in UTC, falls under Today, and its Date says the 14th. From / To covers both
   whole days, From after To is swapped, From alone works, and an empty range says so.
2. **No dates.** Every entry shows, the buttons are on the screen, and a date that does not exist is ignored.

Re-run: SupplierLedgerDrCr (7 tests), SupplierFinanceDirect, SupplierLedgerDaily.

## Results (14 Nov, on `ef076e5d`)

- **SupplierLedgerDrCr:** OK, 7 tests and 51 assertions.
- **SupplierFinanceDirect:** OK, 32 tests and 162 assertions.
- **SupplierLedgerDaily:** OK, 4 tests and 50 assertions.

Sabotage proofs: each change was undone, and the new test failed on its own assertion both times.

1. Filtering on UTC days instead of the shop's days: the Today test fails, because the 02:00 entry falls on the wrong day.
2. Removing the filter, and showing the raw UTC time again: the Today test fails.

## Done on prod (14 Nov 2026)

Deployed with `deploy.sh`. Prod head went from `db1b52c5` to `2db86a82` (merge of `1b7b8379`, pushed first, origin = HEAD). All
13 tenants OK.

Checks after the deploy:

- **Permissions OK.** No role lost anything, and no user lost a role. Every grant count is unchanged.
- **Accounts OK.** Every tenant's chart of accounts is exactly as it was before the deploy.
- **Smoke 13 / 13 at 0:** `tb_diff`, `neg_on_disallowed` and `department_negative`.
- **Errors in today's log:** 20 before, 20 after.

Live proof on the catering tenant, rendered in memory as its Owner with the day-by-day switch off. Nothing was saved.

- **A-ONE TANDOOR (#167):**
  - **No dates:** both entries, the journal (29 Oct 16:33) and the payment (13 Oct 14:13). The payment was
    09:13 in UTC; the column now shows Karachi time.
  - **13 Oct:** the payment only.
  - **1 to 29 Oct, typed backwards:** both entries.
  - **Today:** none, with "No ledger entries between these dates."
- **Busiest supplier yesterday (13 Nov):** Yesterday shows 1 entry, the same as a direct count of that day.
- No supplier entries had been typed yet today, so Today with rows is proven by the test only.
