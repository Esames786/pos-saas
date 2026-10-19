# POS-REMINDER-PAUSE-1 — pause Reminder slips for one terminal, for the rest of the shift

Requested 2026-10-05 for **khatribiryani** (Dine In counter prints ~3 Reminder slips per order).
Branch `feat/pos-reminder-pause-20261005`, off `origin/feat/14d-2-plan-upgrade-requests` (`7fc4a88`).

## What the owner asked for

"If the user wants to stop Reminder for his session or terminal" — a small switch on the POS screen.

## Decision

One switch, on the POS screen, per **terminal**, lasting until **that terminal's shift closes**.
The next shift starts with Reminder ON again — a switch forgotten OFF cannot silently carry into
tomorrow.

Permanent OFF already exists (deactivate the reminder rule in Printing → KOT Routing), so no second
switch is added in settings.

> Open with the owner: "until the shift closes" was my recommendation; the owner approved the plan
> without picking between that and "until someone turns it back on". Built as shift-scoped. Changing
> it later is a one-line change in `ReminderPauseService`.

## Where it sits

The context row at the top of the products panel, beside the terminal name and the shift badge —
the row that already says "No terminal — auto receipt/KOT print is off":

```
ON : 🏪 Kashif Beef Khatri Biryani · 4-Dine In  [Shift open]  [🔔 Reminder ON]   [Change]
OFF: 🏪 Kashif Beef Khatri Biryani · 4-Dine In  [Shift open]  [🔕 Reminder OFF]  [Change]   (amber)
```

- Shown only on a terminal that has an active Reminder rule (Khatri today: 4-Dine In only).
- A user without the permission sees the state, not a button.
- Turning OFF asks once ("until this shift closes"); turning ON does not ask.

## What OFF does

| Slip | While paused |
|---|---|
| First "REMINDER" and every "UPDATED ORDER" | not printed |
| KOT, Receipt | unchanged |
| "CANCELLED" / "CANCELLED ORDER" reminder | printed **if this order already has a Reminder on paper**, so nobody works from a stale slip; skipped if it never had one |
| Manual reprint from Recent Orders / Print history | unchanged — the operator asked for it |
| An "Ask" confirmation already on screen | unchanged — the operator answers it |

After turning ON again, the next round prints as usual. Revision numbers count KOT rounds, so an order
whose first round was paused gets an "UPDATED ORDER" slip next — with the whole current order on it.

## Which terminal

The terminal Reminder routing already uses: the **order's** terminal for normal and added rounds
(`reminderRoutesForSale($sale)`), the **cancelling** terminal for cancellations (RECALL-REPRINT-TERMINAL-2).
The POS switch acts on the terminal selected on that POS.

## Design

| Piece | Change |
|---|---|
| `shifts` | `reminders_paused_at` (nullable timestamp), `reminders_paused_by_user_id` (nullable FK users). Additive. A new shift row = ON. |
| `ReminderPauseService` (new) | `isPausedForTerminal()`, `statusFor()`, `pause()`, `resume()` — one place for the rule. |
| `PrintJobService::planRemindersForKotJobs` | Returns an empty plan flagged `paused` when the order's terminal is paused. This is the single choke point for normal/addition reminders: both the POS KOT endpoint and Review & Pay (`DirectPayPrintOrchestrator`) go through it. Edge does not plan reminders. |
| `PrintJobService::queueCancellationReminders` | Skips only when paused AND the order has no Reminder job yet. |
| `DirectPayPrintOrchestrator` | `reminder_status = 'paused'` (not a pending/retry state, so no "Printing needs attention"). |
| `ShiftController::posStatus` (`/api/pos/shift-status`) | Adds `reminder` {available, paused, paused_by, paused_at, can_toggle}. The POS already calls it on terminal change and every 5 minutes. |
| `POST /api/pos/reminder-pause` (`tenant.api.pos.reminder-pause`) | Toggle. `tenant.api.pos.*` bypasses route-permission middleware, so the controller checks `tenant.pos.pause-reminders` itself (same pattern as Quick Report Send), plus `assertPosSelection` and an open shift (locked row). |
| Permission `tenant.pos.pause-reminders` | Migration creates it and grants it to Owner + every role that can hold a POS order (`tenant.held-sales.store`). Owner can revoke per role in the Permission Center. No `deploy.sh` grant needed. |
| POS blade | Badge/button in `#order-controls-row`; state from the shift-status response. |

No route-catalog work: `tenant.api.pos.*` is exempt from route permissions.

## Not doing

- No settings-screen switch (KOT Routing already turns a rule off permanently).
- No per-order tick.
- No Edge change (Edge is production-disabled and does not plan reminders).

## Acceptance

1. Paused on a terminal → its orders' KOT prints, no Reminder job is created; another terminal is unaffected.
2. Resume → the next round prints a Reminder.
3. Close the shift, open a new one → Reminder is ON without anyone touching it.
4. Cancellation while paused: order with a Reminder on paper → correction slip prints; order without → none.
5. Review & Pay while paused → paid, KOT queued, `reminder_status = paused`, nothing pending.
6. No permission → 403, nothing changes; status shows `can_toggle = false`.
7. No open shift → refused, nothing changes.
8. `/api/pos/shift-status` reports the state; a terminal without a Reminder rule reports `available = false`.
9. Migration grants the permission to Owner and to roles holding `tenant.held-sales.store`, idempotently.
10. POS blade compiled and its generated PHP linted; the switch renders.
11. Existing reminder / cancellation / direct-pay tests stay green.

## Deploy notes

Additive migration only. After deploy: check on khatribiryani which roles received
`tenant.pos.pause-reminders` (read-only), and `system:clear-tenant-permission-cache` if a role misses it.
