# BILLING-DATE-KEEP-1 — paying an invoice never pulls the subscription end date back (31 Oct 2026)

## Why

On 31 Oct 2026, 00:10 UTC, the daily sweep `saas:subscriptions-expire` set a live restaurant tenant to `past_due`, and
its counters showed "Module Not Available — Your subscription is not active". Its subscription's
`current_period_ends_at` was `2026-10-31 00:00:00`. That came from its paid October invoice's `period_end`, which is a
DATE, so it is stored as midnight. The last paid day did not count.

Owner, the same morning: "fix it fast", then "next year ki kardo". Status was set to `active` and the end date to
`2027-10-31 23:59:59` by hand. The old row was saved on prod at `/tmp/khatri_sub212_before_20261031_071457.json`.

The trap that would undo it is `SubscriptionBillingService::activateSubscriptionFromPaidInvoice()`. When a
subscription/upgrade invoice becomes paid, it writes `current_period_ends_at = invoice.period_end` outright. Paying the
next monthly invoice (cycle 16 Oct – 15 Nov) would have set the tenant back to 15 Nov 00:00 and locked it again that
night. The same applies to every tenant.

Owner: "baqi k lye b kardo invoice paid karne pe subscription date na hile".

## Change

`activateSubscriptionFromPaidInvoice()` keeps the later of two dates:

- the subscription's current end date;
- the invoice's `period_end` at **23:59:59**, so the paid last day counts.

The end date only moves forward. Status and plan behave as before. Add-on (usage) invoices still never touch the
subscription (`extendsSubscriptionPeriod()`, unchanged).

## Impact (system map)

- **Table:** master `subscriptions` (`current_period_ends_at` only). No migration, no tenant DB.
- **Screens / routes:** all reach the service, which changed; nothing else did.
  - Master → Invoices → Record payment (`central.invoices.payments.store`).
  - Master → Invoices → Verify payment (`central.invoices.payments.verify`).
  - Plan-upgrade invoices, through the same service.
- **Not changed:**
  - The sweep (`SubscriptionLifecycleService::markExpiredSubscriptionsPastDue`) still flips at `ends < now` and ignores the
    gate's 3-day grace.
  - Master → Tenant edit still sets the date by hand.
  - Known, not asked: paying an old monthly invoice after an admin changed the plan sets the plan back to the invoice's
    plan. That is the same code line and was not touched.
- **Tests:**
  - New: `tests/MySql/BillingDateKeepMySqlTest.php`. It runs through the real Master routes: record payment, and pending
    then Verify.
  - Neighbours: `WhatsAppUsageLedgerMySqlTest` (add-on invoices never move the period; subscription invoices still extend
    it), `BillingMonthlyInvoiceMySqlTest`, `MasterUniverseMySqlTest`.

## Results
- **New test, on the fix:** `BillingDateKeepMySqlTest`, 4 tests. A one-year date stays after a payment; it also stays when
  an older invoice is paid; a lapsed subscription is carried to 23:59:59 of the paid last day; the nightly sweep leaves it
  active on that day and flips it the next day; pending then Verify keeps the later date; a newer invoice still moves the
  date forward.
- **Sabotage:** the same test on the OLD code (detached `5bdaac80`, DB set `_bdx`) gives 4/4 red. The first failure is
  `'2027-10-31 23:59:59'` expected and `'2026-11-15 00:00:00'` actual, which is exactly the trap.
- **Related run** (DB set `_bdk`, one process, tree frozen): 18 MySQL files that touch subscriptions, invoices or the
  access gate. **174 tests / 1772 assertions, 0 failures.**
- **Also fixed here (test only):** `MasterUniverseMySqlTest::test_the_master_theme_never_reaches_a_tenant_page` was RED on
  the production branch since MASTER-TABLE-COLOURS-1 (`ca85d2f1`).
  - Its parser split selectors at every comma, including the commas inside `tr:is(.table-warning, .table-success, …)`,
    so it read `.table-success` as unscoped. The stylesheet is scoped correctly, so nothing on prod was affected.
  - It now splits only at top-level commas. Proven against the real file (352 selectors, 0 unscoped). It still catches
    `.loose`, `.x:is(.a, .b)` and `…:is(.a, .b), .c`.
  - The MASTER-HISAB-1 record said the CSS edit during that run "is not executed by tests". That was wrong: this test
    reads the file.

## Deployed — 31 Oct 2026 ~08:30 UTC (13:30 PKT), merge `f8af185a`

- **Before the deploy:**
  - Owner's word: "kardo deploy".
  - Production had moved on twice since this branch's base: SHIFT-HANDOVER-1 `5bdaac80`, then product advanced
    settings `b78d8dba` (record `51046d03`). Production was merged into the branch (`77d87bcf`); only the system map
    conflicted. Against production the merge differs by this release's 4 files only, and none of the other release's 28
    files touch billing.
  - The merged tree was NOT re-run. Local MySQL was down at the time and another session's tests were using it, so it was
    not started from here. The tested code is byte-identical on top.
  - origin == HEAD == `f8af185a` was checked before `deploy.sh`.
  - Master backup `20261031_082935`. RECON=before was taken at 08:29.
  - PERM_SNAP=before did NOT run: `/tmp/perm_snapshot.php` had vanished from prod. The after-compare therefore used the
    30 Oct 21:34 snapshot, and the script was put back from a local copy.
- **`deploy.sh`:** tenants ok=11 skipped=2 (the two demos with no tenant DB, as before). No migration in this release.
- **After the deploy:**
  - Smoke clean: tb_diff 0, neg_on_disallowed 0, department_negative 0.
  - **PERMISSIONS OK:** every role's grants identical (master 70→70), no user changed.
  - Reconciliation: GL totals unchanged everywhere. The 6 "unexplained" are kashiffood and khatribiryani POS tables: one
    held order each was saved at the counter in the window (2 old lines gone, 3 re-inserted, 2 KOT links blanked each;
    `recon_explain.php`). That is live use.
  - The deployed file holds the fix (line 151).
  - The access gate is OK for the live tenants. The one tenant reopened by hand this morning keeps `2027-10-31 23:59:59`.
  - The only new log error is `printing:autoclose-stuck`, which has failed every 15 min since 21 Oct (demos with no DB;
    already in open items).
- **Still to do:** rebuild the system map once local MySQL is back. The branch's map is production's (`b78d8dba`) and does
  not have this release.
