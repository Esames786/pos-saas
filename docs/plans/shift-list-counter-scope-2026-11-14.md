# SHIFT-LIST-COUNTER-1 — 14 Nov 2026

The owner, passing on a client's complaint: the Takeaway user and the Quick Sale user could see each other's data. "Ye band
kardo, apna apna chale."

## What leaked

Each counter cashier is bound to his own counter (`terminal_user`). That binding already limits the dashboard, the Report
Center, the sales list, held orders, print jobs and returns (USER-DATA-SCOPE-1), and it limits which shift he may **close**
(`assertCanOperateTerminal`).

Three shift screens ignored it:

| Screen | Before | Now |
|---|---|---|
| Shifts list `/shifts` (and its "N open" counts) | every counter's shifts, opening cash included | only the cashier's own counters |
| Shift page `/shifts/{id}` | any shift by id, all seven figures | 403 for another counter's shift |
| Close screen `/shifts/{id}/close` (GET) | any open shift, expected cash included | 403 for another counter's shift |

The POST close was already refused. The page simply showed what the cashier was not allowed to act on.

## Why not a permission change

The POS has no Close Shift button. A cashier closes from **Shifts → Close**, and the close lands on `/shifts/{id}`. Taking
away `tenant.shifts.index` or `tenant.shifts.show` would stop cashiers closing their own shift.

## Rule

`ShiftController`:

- `index` and its open counts add `whereIn(terminal_id, UserDataScope::terminalIds($user))`.
- `show` and `closeForm` call `denyOtherCountersShift()`, which uses the same `canOperateTerminal()` rule the close uses.

Users with no terminal binding, and users bound to every counter (owner, managers), see every shift as before.

No switch. This is the same rule the close already enforced, and nobody loses a workflow, only the sight of another counter's
drawer.

## Impact

Taken from the system map (built 11 Nov) and a read-only probe of every live tenant on 14 Nov.

- **Table:** `shifts`, read only. No schema change.
- **Screens:** `tenant.shifts.index`, `tenant.shifts.show`, `tenant.shifts.close-form`. Not touched: Close Branch (already
  filtered by counter), the POS shift status, and the dashboard's Open Shifts card (already filtered by counter).
- **Users whose view narrows:**
  - Khatri: 4 counter users, each down to their own counter.
  - Kashif Food:
    - Delivery Desk and delivery_kf2 stop seeing the 3 DTQ counters.
    - DTQ 2, DTQ 3 and DTQ3-B stop seeing Delivery.
  - Tawakal / The Kashif Foods: the 2 counters stop seeing each other.
  - demo: 2 users.
  - Every other tenant: no change.
- **Still not limited:** the riders report and the customer ledger. Neither carries takeaway or quick-sale counter data;
  they are left as they are.

## Tests

New: `tests/MySql/ShiftListCounterScopeMySqlTest` (4 tests, over real HTTP):

- A counter cashier's list shows his counter only, including its opening cash and its open count. The reverse holds for
  the other cashier.
- The owner (bound to both counters) and an unbound manager still see both counters, and both screens open.
- Another counter's shift returns 403 on the page and on the close screen, and the amount does not appear in the body.
  The cashier's own shift opens on both screens.
- Closing his own shift redirects to its page, and that page opens.

With the controller change removed, the list test and the by-id test fail.

Re-run:

- ShiftTerminalRestriction, ShiftTerminalScope, ShiftBranchUx, ShiftHandover, HideAmountsFromOperators,
  StarterPlanCounter, DailyClosingReconciliation, PosReminderPause, ReportDeliveryV2, SaleCashSemantics, PermissionCenter.
- Unit `PosFrontendRegressionTest`.

## Same day, data only

Khatri's second takeaway login `takeaway_2` (#9, made 13 Nov) was removed at the owner's word. It had no sales and no
shifts; only its role, counter, branch and manager-PIN links were deleted.

Results, 14 Nov:
- Every file listed is green.
- `StarterPlanCounterMySqlTest` stopped in its own setUp on a brand-new master test DB ("the pos module is seeded"). That
  is a precondition of the test, not this change. After `MasterSeeder` and `system:routes-sync` on that test master DB it
  passed: 3 tests, 50 assertions. The new test and `HideAmountsFromOperators` were re-run on the seeded master: 24 tests,
  124 assertions.

## Done on prod (14 Nov 2026, ~21:30 UTC)

- **Code:** prod `db1b52c5`, a merge of the tested `4769655e` (`git diff 4769655e db1b52c5` is empty). Pushed; origin
  matched HEAD before `deploy.sh`. All 13 tenants OK.
- **Checks:**
  - Permissions: PERMISSIONS OK. No role lost anything, no user lost a role, and grants are unchanged on all 14
    databases.
  - Accounts: ACCOUNTS OK, every chart is unchanged.
  - Smoke: 13 tenants, `tb_diff=0`, `neg_on_disallowed=0`, `department_negative=0`.
  - Errors: 23 in today's log before the deploy and 23 after.
- **Live proof** (read-only: the deployed controller called in memory as the real users):
  - Takeaway Counter: the list shows only 1-Takeaway (83 shifts). The Quick Sale shift page returns 403 "This shift
    belongs to another counter."
  - Quick Sale Counter: the list shows only 2-Quick Sale (52 shifts). The Takeaway shift page returns 403.
  - Owner: all four counters (319 shifts), and both pages open.
  - No shift was open at the time, so the close screen was proved by the test only.
- **Order types are unchanged.** The owner confirmed that both counter users keep both takeaway and quick sale.
