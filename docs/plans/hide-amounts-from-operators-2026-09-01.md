# Hide the money from the counter — research before building

**Status:** RESEARCH ONLY. No code written, no migration, nothing deployed.
**Date:** 2026-09-01
**Owner's ask:** on the closing screens and on the dashboard, an operator should see `*****`
instead of the figures. Only the admin sees the real numbers. Per branch. Off by default.

---

## 1. What was asked, restated

| # | Ask |
|---|---|
| 1 | **Close Branch / Close Shift** — hide **Expected cash** and the **cash / card / bank / discount breakup**. Show `*****`. |
| 2 | **The counter user may only type what they counted** — nothing else on that screen. |
| 3 | **Dashboard tiles** — Net Sales, Orders, Avg Order Value, Cash, Card/Bank, Discounts, Tax, Open Shifts, Expiry Alerts — the same treatment. |
| 4 | Controlled **per branch**. |
| 5 | "Admin" = the **Owner**, expressed as a **permission** — the same shape as `tenant.dashboard.details` (31 Aug), so it can be handed to a manager later without touching code. **Confirmed by the owner.** |
| 6 | The Tawakkal / Kashif Foods work stays on its own branch; this is separate work. |

### The one assumption this document is written under

> **Default = the numbers stay visible.** The branch setting starts OFF, and hiding is something
> the owner switches on for a branch. Read from *"agar mai branch pe hide kardon to shift pe data
> hide rahe ga warna on"* — hiding is an action the owner takes.
>
> **This matters and is worth one word of confirmation before building**, because the other reading
> — hidden everywhere from the moment it deploys — would change what Khatri Biryani's and Kashif
> Food's operators see on a live cash-counting screen tomorrow morning, with nobody having asked
> for it. Everything below is designed so that flipping this default later is a one-line change.

---

## 2. Where the numbers actually are

Read out of the current code, not from memory.

### 2.1 Close Branch — `resources/views/tenant/shifts/close-branch.blade.php`

| Line | What it prints |
|---|---|
| 39, 41 | `$sumExpected`, `$sumSales` — the footer totals |
| 84–89 | the breakup sub-line: `cash … · card … · bank … · discount …` |
| 119 | per-terminal **Sales** |
| 120 | per-terminal **Expected** |
| **123** | `data-expected="…"` on the Counted input |
| **125** | the Counted input is **pre-filled with the expected cash** |
| 140 | `data-expected` again, on the branch-total row |
| 191–196 | JS that computes the live difference and prints `Exact` / short / over |

Fed by `ShiftController::closeBranchForm()` (line 256), which passes whole `Shift` models —
`expected_cash`, `total_sales`, `total_cash`, `total_card`, `total_bank_transfer`,
`total_discount` all ride along on the model.

### 2.2 Close Shift (single terminal) — `resources/views/tenant/shifts/close.blade.php`

| Line | What it prints |
|---|---|
| 35 | Expected cash, large |
| 55 | `data-expected` on the input |
| 62 | *"Expected 224,305.00"* under the input |

### 2.3 Dashboard — `resources/views/tenant/dashboard.blade.php`

Nine tiles (lines 78–208) reading `$today[...]`, `$openBills`, open-shift and expiry counts.
Line 88–94 also prints `billed − returns` and the `still open` / `expected` lines added on 31 Aug.

---

## 3. The finding that shapes the whole design

**The Counted box is pre-filled with the expected cash, and `data-expected` puts that number in the
HTML source.**

So "hiding" cannot be a CSS or Blade-only trick:

- if the input still pre-fills, the operator reads the expected amount **in the box they type into**;
- if `data-expected` is still emitted, the number is one *View Source* away;
- if the live difference still computes in the browser, `Exact` tells the operator the expected
  amount by trial and error in three keystrokes.

This is the same rule the 31 August dashboard work already wrote down in its own comment:

> *"The blade's `@can` alone would not be enough: the queries would still run, and 'hidden' would
> mean rendered-then-dropped rather than never fetched."*

**So the number must never reach the page.** Which turns out to be exactly what ask #2 wants
anyway: with no pre-fill and no expected on screen, the operator has no choice but to count the
drawer and type the real figure. The two asks are the same change.

---

## 4. Proposed design

### 4.1 Two independent switches, and both must be on to hide

```
branch flag ON   AND   user lacks the permission   →   *****
otherwise                                          →   the number, as today
```

Nothing changes for any existing tenant, because the flag ships **off**.

### 4.2 The branch flag

```php
// migration: additive, nullable-free, default 0
$table->boolean('hide_amounts_from_operators')->default(false)->after('sales_return_approval_mode');
```

Same shape as `sales_return_approval_mode` (1 Sep), whose migration comment already argues why a
new control must default to today's behaviour rather than to the stricter one.

Set from the **Branch edit form** — one checkbox, worded for a person:
*"Hide cash figures from counter staff — they will only enter what they counted."*

### 4.3 The permission

`tenant.shifts.view-amounts` — synthetic, no route behind it, exactly like `tenant.dashboard.details`.

The migration follows the proven pattern (`2026_08_31_000001`):

- create the permission row if absent;
- **grant it to every existing role**, not only Owner.

> That back-grant looks wrong at first glance — "only admin should see it". It is not. The flag is
> what hides the figures, and it is off everywhere. Granting the permission to everyone at
> migration time means the *permission* changes nothing on its own; the owner then **revokes it
> from the operator roles** on the branch they want restricted, which is the same two-step the
> Kashif Food hides used on 31 August. Granting to Owner only would silently strip the figures
> from every manager on every tenant the moment it deploys — the exact mistake that migration's
> comment was written to prevent.

### 4.4 Where the masking happens

**In the controller, never in the Blade.** Each screen decides once and passes a boolean plus
already-masked values.

| File | Change |
|---|---|
| `ShiftController::closeBranchForm()` | resolve `$maySeeAmounts`; when false, blank the money fields on the shift models before they reach the view |
| `ShiftController::closeForm()` (single shift) | same |
| `DashboardController::index()` | same, next to the existing `$maySeeDetails` |
| the three Blades | `@if($maySeeAmounts) … @else `*****` @endif`, and **no `data-expected`, no pre-filled value, no difference JS** when hidden |

`ShiftService::closeShift()` is untouched. It still computes the expected cash server-side,
still records the variance, and `CASH-SHORTAGE-1` still raises its draft expense voucher when the
count comes up short. **Hiding the number from the screen must not stop the system from knowing
it** — that is the whole point of counting blind.

### 4.5 Which branch's flag applies on the dashboard

The dashboard has an **All Branches** selector, so "the branch" is not always one branch.

Rule: **mask when the user lacks the permission and *any* branch in their scope has the flag on.**
Fail closed. In practice an operator is scoped to one branch anyway, and an Owner holds the
permission, so the ambiguous case barely arises — but it should not resolve in favour of showing
money.

---

## 5. What this must not break

| Risk | Answer |
|---|---|
| An existing tenant's operators lose figures on deploy | The flag ships **off**; the permission is back-granted to every role. Two switches, both must move. Guard test asserts a tenant that changed nothing renders identically. |
| The variance / short-cash voucher stops working | `ShiftService` is not touched. Server still computes expected and records the difference. Guard test closes a shift with the flag on and asserts the shortage is still recorded. |
| The number leaks in the HTML source | Guard test asserts the raw response body does **not** contain the expected amount when hidden — the `data-expected` and pre-fill traps specifically. |
| The operator cannot close a shift at all | They can: the Counted box still accepts input. It is simply empty instead of pre-filled. Guard test closes a shift as a masked operator. |
| Owner loses anything | Guard test: Owner sees every figure with the flag on. |
| Reports become a side door | Report Center is already permission-gated and was revoked from Kashif Food's operator roles on 31 Aug. Worth stating in the doc; no code needed. **But see §7.** |

---

## 6. The guard tests

All over real HTTP, and each proven to bite by putting the bug back.

1. Flag off → every screen renders exactly as today, for every role.
2. Flag on + operator → Close Branch shows `*****`; the response body contains neither the expected
   amount nor `data-expected`; the Counted input is empty.
3. Flag on + operator → Close Shift, same.
4. Flag on + operator → the nine dashboard tiles are masked.
5. Flag on + **Owner** → every figure visible.
6. Flag on + operator → closing still works, and a short count still records the variance and
   raises the draft voucher.
7. Flag on at branch A only → branch B is unaffected.

---

## 7. Open questions for the owner

1. **The default** (§1). One word: numbers visible by default, or hidden from day one?
2. **The Sales column** on Close Branch — the ask named *Expected* and the *breakup*. The
   per-terminal **Sales** figure sits between them and is the same information in another form.
   Hide it too? *(Recommendation: yes — otherwise the operator reads Sales and knows what the
   drawer should hold.)*
3. **Shift Report and Daily Closing** carry the same figures. Not named in the ask. Bring them under
   the same flag, or leave them to the existing Report Center permission?
   *(Recommendation: leave them — they are already permission-gated, and widening the scope
   silently is how a small change becomes a big one.)*
4. **Which roles lose the permission** on the restricted branch — all three operator roles, or a
   subset? Kashif Food's roles are Delivery / Dine In / Dine In (Restricted).

---

## 8. If approved, the build order

1. Migration: branch flag (default off) + the permission, back-granted to every role.
2. Branch edit form: the checkbox.
3. `ShiftController` × 2, `DashboardController`, and the three Blades.
4. Seven guard tests; each proven to bite.
5. Full suite; then the owner turns the flag on for the branch they want and revokes the permission
   from the roles they choose — a data step, reversible, done the same way as 31 August.
6. Nothing deployed until the owner says so.

*Branch for this work: its own, kept clear of `feat/tawakal_kashif`.*
