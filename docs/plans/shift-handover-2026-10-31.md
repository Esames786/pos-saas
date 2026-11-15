# SHIFT-HANDOVER-1 — hand the counter, with its open orders, to the next cashier (31 Oct 2026)

## Why

A shift cannot close while it owns open work: held orders (`sales_orders.status = held`, its `shift_id`) or
open tables (`restaurant_table_sessions.opened_shift_id`). One terminal holds one open shift, so the next
cashier cannot open the counter until the old shift closes. The outgoing cashier therefore waits for every
order still out with a rider and every table still seated.

Prod, read only, last 30 days:
- One live restaurant's Delivery counter changes cashier every evening on the same terminal: the day user works
  about 12:00–18:00, the next user 18:00–01:00. The old shift closed between 17:42 and 18:22, and the next one
  opened 1–15 minutes later.
- The other live restaurant never changes cashier during the day.
- Neither tenant had a held order older than a day. Not one paid order was paid on a shift of a later
  business day: the close guard makes that impossible.

## What the owner decided (31 Oct)

- First the client asked for a "force close", allowed only when another shift of the same day is open.
  The owner then narrowed it for security: **no handing orders to another counter.** The outgoing cashier
  hands in only his own cash, then opens the **next user's shift on the same counter**. The open orders move
  into that new shift.
- The next cashier types **his own password** on the same screen, so no shift opens in someone's name without
  them.
- It must work on **both** close screens: Close Shift (one terminal) and Close Branch (all terminals).
- It sits behind a Master switch, OFF everywhere, and is turned on per tenant.

## Rules

- The next cashier must be an active user who works this counter (`terminal_user`, or unbound) in this
  branch. He cannot be the user closing or the user whose shift it was.
- **Same business day only.** A shift opened after midnight is stamped with the next date. Moving
  yesterday's orders into it would put one day's sales in another day's drawer. After midnight the open orders
  are settled first, as before. This also keeps the client's rule "the last shift of the day cannot be force
  closed".
- Count as usual. The blank-count guard applies, and in Close Branch "one total" mode each drawer closes at
  its expected. A short drawer gets the same draft voucher as any other close.
- Only `shift_id` of the open held orders and `opened_shift_id` of the open table sessions move. The order's
  terminal, business date, amounts and KOT routing do not. Money paid later goes to the drawer that takes it
  (POS-SHIFT-ATTRIBUTION-1, `4bdda60`); at this counter that is the new shift.
- "Every open order belongs to an open shift" still holds. Split, Add Round, Move, KOT and cancel rely on it.
- Close Branch is still all-or-nothing. A busy terminal without a handover still refuses the whole close.
- If the next cashier's order types do not cover an order, the screen warns him, but the handover is not
  refused (owner: access can be given in Users).

## How

- `ShiftService::closeAndHandOver()` runs one transaction. It locks the shift and re-checks the counter
  rule and the date under the lock, takes a locking read of the open work, then closes the shift, opens the
  next shift through `open()`, re-checks that the new shift has the same date, and moves the rows. It also
  provides `handoverCandidates()` and `handoverSameDay()`.
- `ShiftController::close()` / `closeBranch()` take an optional `handover` (`[user_id, password,
  opening_cash]`, or keyed by shift id on Close Branch). They check the password before locking anything,
  limited to 5 wrong tries a minute per user. The routes and permissions are unchanged: whoever may close may
  hand over.
- The two close screens include `tenant/shifts/partials/handover.blade.php` only when the switch is on and
  the shift has open work. It shows the open orders, the next cashier, his password and his opening cash, and
  a live line saying what will happen. The help text is in English plus Roman Urdu.
- The switch is `TenantFeatures::SHIFT_HANDOVER` (`shift_handover`). There is no migration.

## Impact (system map)

- **Tables written:** `shifts` (one closed, one new), `sales_orders.shift_id` (open held orders of that
  shift only), `restaurant_table_sessions.opened_shift_id` (open sessions of that shift only),
  `daily_closings` (Close Branch "one total", unchanged logic).
- **Screens:** `tenant.shifts.close-form` / `tenant.shifts.close`, and `tenant.shifts.close-branch-form` /
  `tenant.shifts.close-branch`. No new route and no new permission.
- **Tests:** new `tests/MySql/ShiftHandoverMySqlTest.php`, which goes through the real routes, middleware
  and permissions. Existing shift tests are run again as regression.

## Left alone

- Handing orders to another counter's shift. The owner refused it for security.
- Anything after midnight.
- The Edge local shift endpoint, which is not deployed and still uses plain `closeShift()`.
