# POS-PIN-FOCUS-1 — the manager code box in the pay window takes the cursor (10 Nov 2026)

## The report

Owner, 10 Nov, with a photo of the counter: "discount manager pin input pe click nhi hora na type hora hai fix it".
Screen: dine-in table → **Close & Pay Table Bill** → discount → **Manager Approval**. The code box could not be clicked,
and typing went somewhere else.

## Why

- The discount is applied inside the pay window (`#paymentModal`, a Bootstrap 5.3.8 modal). With the branch's discount
  mode = `manager_required`, `showManagerPinModal()` opens a SweetAlert2 box, which SweetAlert puts on `<body>`,
  **outside** the pay window.
- Bootstrap's modal focus trap listens to `focusin` on the document: any focus that lands outside its window is pulled
  back to the window's first field. So clicking the code box (or the box focusing itself on open) put the cursor straight
  back into **Tendered Amount**. In Chrome, typing `1234` turned Tendered `200.00` into `1234200.00`, and the code box
  stayed empty.
- The same box with no window open (void from the cart, cancel held order from the cart) worked, which is why it was not
  seen before. Live: one tenant switched its branch to `manager_required` on 8 Nov 21:10 UTC. Its discounted bills fell
  from 6–13 a day to 1. Only one `manual_discount` approval exists in its whole history. Branches on `auto_approve` never
  open the box.
- Bootstrap and SweetAlert files have not changed since the first commit (May). The pay window and the discount code
  path date from June and August.

## Fix

`resources/views/tenant/pos/index.blade.php`: one capture-phase `focusin` listener on `window`. When the focus lands
inside `.swal2-container`, the event stops before Bootstrap's document listener sees it. Every other focus is left to
Bootstrap, so the pay window still keeps the cursor inside itself against the page behind. No server change. The other
PIN and SweetAlert inputs opened from a window are covered the same way, for example a `select` over a window.

## Impact (system map)

- Tables: none. Routes and controllers: none.
- Screens: `/pos`, every SweetAlert box opened while a POS window is open (pay window discount → Manager Approval;
  cancel-reason select).
- Tests: `tests/MySql/PosScreenRendersHttpMySqlTest.php` (the page ships the guard and the code box; with the guard
  removed the test fails). `tests/Unit/Tenant/CancellationPolicyRegressionTest.php` stays green.

## Proof

Chrome (headless), with the app's real `bootstrap.bundle.min.js` 5.3.8 and `sweetalert2.all.min.js` 11.22.2 and the
POS view's real "Manager PIN Modal" section cut from the blade:

| check | live code | fixed |
|---|---|---|
| cursor when the box opens over the pay window | `tendered_amount` | `swal-manager-pin` |
| after clicking the box | `tendered_amount` | `swal-manager-pin` |
| code box after typing 1234 | empty | `1234` |
| Tendered Amount after typing | `1234200.00` | `200.00` (untouched) |
| Enter | nothing | verify sent, approved, box closed |
| focus back in the pay window after the box | yes | yes |
| Bootstrap still pulls a stray focus back into the pay window | yes | yes |
| SweetAlert select over a window | answered | answered, and focusable |
| box with no window open (cart path) | works | works |
