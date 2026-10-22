# Transaction reset: supplier-invoice tables + fail closed (22 Oct 2026)

Supplier-bill completion, kept separate from W2. Branch `fix/reset-supplier-bill-tables-20261022` off `5441ead2`.
Status words: implemented / tested / merged / deployed / activated.

## Why

The frozen baseline on `5441ead2` had one failure: `CateringTenantResetMySqlTest::test_no_tenant_table_is_left_unclassified`.
SUPPLIER-NET-BILL-1 added two tables that `tenant:reset-transactions` neither wiped nor kept on purpose:

- `purchase_bill_return_nettings`
- `supplier_credit_reallocations`

The reset truncates with foreign-key checks off. Both tables would have survived with rows pointing at deleted bills
and returns, and a new return reusing an old id would collide with the netting row's unique key.

Worse, the command only **warned** about unclassified tables and carried on deleting.

## What changes

1. **Both tables are wiped**, listed ahead of `supplier_credit_allocations` and the bills and returns they point at
   (children first).
2. **Fail closed.** One unclassified table now stops the reset before the first destructive statement. The message
   names the tables and says nothing was changed. The owner's "System reset" screen shows that message, as it already
   shows any failure.

No migration. No schema change. No reset is run on any live tenant.

## Impact (system map + read-only production census, 22 Oct)

- **Code:** `app/Console/Commands/TenantResetTransactionsCommand.php`.
- **Callers:**
  - the owner's `/system-reset` screen (`SystemResetController`);
  - by hand, from the shell.
  - Nothing schedules it.
- **Census** (production code `59787ff6`, every tenant, read-only via reflection of the command's own lists): **0
  unclassified tables on all 11 tenant databases.** Fail-closed would stop no reset that runs today. The two new tables
  ship with the unified branch together with their classification.
- **Tests:**
  - `SupplierNetBillMySqlTest::test_a_transaction_reset_wipes_nettings_and_credit_moves_and_keeps_every_master_row`
    — real records through the services (an older open bill, a return, a net invoice that moves the credit), then
    the reset on the test's own disposable tenant. It proves:
    - nettings, credit moves, allocations, bills, returns, ledgers, journals and stock are wiped;
    - every kept table is byte-identical: products, suppliers, accounts, users, roles, permissions,
      role/permission links, settings, plus a pinned must-survive list independent of the command's own lists;
    - master `tenants`, `tenant_databases`, `plans`, `plan_modules` and `subscriptions` are unchanged;
    - a new return and net invoice post cleanly afterwards.
  - `TenantResetFailClosedMySqlTest` — an unknown table stops the reset with every row of every table unchanged; the
    same reset runs once it is gone.
  - `tests/MySql/Support/RegistersTestTenant` maps a disposable tenant code to the test database and puts master back
    afterwards.

## Found by the frozen full suite on `c09d4f8d` (fail-closed working as intended)

The full suite ran 2,366 tests: 2 failures, 1 skip (GeoIP file), 1 PHPUnit deprecation. Both failures were the new
reset tests. By the time they ran, the Edge tests had built the Branch Server schema (`database/migrations/edge`) in
the shared test database. Seven of those tables were unclassified, and fail-closed refused to reset:

- `edge_auth_audit`, `edge_consumed_assertions`
- `edge_local_print_deliveries`, `edge_local_print_worker_state`
- `edge_operational_stock_balances`, `edge_operational_stock_baselines`, `edge_operational_stock_movements`

These tables never exist on a cloud tenant: the production census found 0 unclassified tables. The command also
refuses to run on a Branch Server. So they are classified as **kept** (never touched), like the two Edge tables
already listed. The whole-schema check in `CateringTenantResetMySqlTest` only sees the tables present when it runs.
A new `test_the_branch_server_tables_are_classified_too` builds the Edge schema itself, so the answer no longer
depends on test order.

Re-run with Edge tests first, then the reset tests: 59 / 59. Sabotage (Edge tables unclassified again) → red.

## Seen, not changed (outside this narrow fix)

A reset keeps master rows as they are, including stored running balances: `suppliers.current_balance`,
`customers.current_balance`, and `cash_bank_accounts.current_balance`. Their ledgers are wiped, so after a reset those stored balances
no longer match an empty ledger. This is pre-existing. It needs its own decision (zero them, or recompute) and its own
step.
