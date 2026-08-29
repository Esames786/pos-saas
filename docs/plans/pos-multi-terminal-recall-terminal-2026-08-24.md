# POS-RECALL-TERMINAL-1 — recall must not change the operator's active terminal

**Date:** 2026-08-24
**Scope:** POS workspace (`resources/views/tenant/pos/index.blade.php`) — front-end only. No schema, no route, no backend-contract change.
**Status:** design + applied (deploy pending owner approval — Khatri live POS + canonical freeze).

---

## Problem

The active POS terminal has **no server-side home** — it is purely the value of the DOM
`#terminal_id` `<select>` (persisted per-browser in `localStorage['pos_terminal_<branchId>']`).
`recallHeldSale()` used to **overwrite** that select with the recalled order's terminal:

```js
// index.blade.php (old)
if (sale.terminal_id !== undefined && terminalEl) {
    terminalEl.value = sale.terminal_id || '';
}
```

Because this assignment is programmatic it does **not** fire the `change` listener, so `localStorage`
is never updated — but the DOM select (the sole source of the terminal posted with every sale) is now
the recalled order's terminal. `clearCart()` never resets `#terminal_id`, so after recalling another
terminal's order **every subsequent NEW order is silently stamped with that other terminal** until a
full `/pos` reload. `UserDataScope::assertPosSelection` cannot catch it because, for a legitimate
multi-terminal operator, that terminal is bound to them.

This only bites **multi-terminal operators** (owner/manager, or any user with >1 row in `terminal_user`).
A single-terminal operator only ever sees their own terminal's held sales, so the recalled order's
terminal already equals their select — the hijack was a silent no-op for them.

## Agreed model

> **Recall never changes my active terminal. The order becomes *mine* only when I commit it —
> when I Hold (re-save) or Review & Pay. At that moment it adopts my OWN active terminal, so the
> KOT, the receipt and the sale's attribution all follow my station. Until then it sits untouched
> on its origin terminal.**

Timeline for: operator at **T1**, recalls **T2**'s held order, adds an item.

| Moment | Operator's active terminal | Order's `terminal_id` (DB) | Print result |
|---|---|---|---|
| Recalled (just opened) | **T1 — unchanged** | still T2 (untouched) | nothing |
| **Hold** (new item) | T1 — unchanged | **becomes T1** (posted terminal saved) | new item's KOT → **T1** |
| **Review & Pay** | T1 — unchanged | **T1** (paying terminal) | receipt → **T1**, sale under **T1** |

Original items are **not** re-KOT'd (`kot_sent`/`kot_sent_quantity` per line); their kitchen ticket
already printed on T2 when they were first made. Only newly-added lines route to T1's kitchen.

## Why print routing lands where it does

All physical routing is driven by the **order's** `terminal_id`, never by where the operator stands:

- Receipt printer: `PrintRoutingService::receiptPrinter()` → `TerminalPrinterSetting::where('terminal_id', $sale->terminal_id)`.
- KOT printer: `defaultKotPrinter()` + `mappedPrinterIds()` terminal precedence, all on `$sale->terminal_id`.
- Bill **preview** is the exception: it builds a transient sale from the **posted** terminal (`POSController::billPreview`, `terminal_id => $data['terminal_id']`), i.e. the operator's active terminal.

Under the agreed model the order's `terminal_id` equals the operator's active terminal the instant it is
committed (Hold/Pay), so preview, KOT and receipt all converge on the operator's own station — no split.

## The change

Delete the terminal-sync block in `recallHeldSale()` (index.blade.php). Nothing else: the three write
paths already stamp the **posted** terminal —
`HeldSaleController::store` (create + update branches), `SalesOrderController::store`,
`POSController::billPreview` — so removing the hijack is sufficient for the whole model. `clearCart()`
correctly leaves `#terminal_id` alone because it now always holds the operator's own terminal.

## Consequences / notes

- **Attribution:** the sale belongs to the terminal that committed/paid it (the operator's station) →
  per-terminal closing / Z-report / cash drawer reconciliation match reality (the drawer that took the
  cash owns the sale).
- **Business date / shift:** `business_date` follows the posted terminal's open shift. Under this model
  that is the operator's own terminal (which necessarily has an open shift to operate), so it is *more*
  consistent than the old hijack, which used the recalled order's terminal and could point at a
  different/closed shift.
- **Order type:** still restored from the recalled order (unchanged) — KOT routing then keys on
  (operator's terminal + that order type), which is the correct "this is now my takeaway/dine-in order".
- **Impact surface:** behaviour changes ONLY for multi-terminal operators; single-terminal operators are
  unaffected (no-op as explained above).
- **Out of scope:** branch selection on recall (Khatri is single-branch); a prominent active-terminal
  badge (could be a follow-up UX nicety).

## Guard

`tests/Unit/Tenant/PosRecallTerminalRegressionTest.php` — asserts `recallHeldSale()` no longer writes
`terminalEl.value` from the recalled sale, and that the intent marker `POS-RECALL-TERMINAL-1` is present,
so the hijack cannot silently return. `PosScriptScopeRegressionTest` + the compiled-Blade syntax guard
confirm the view still parses and keeps its top-level script scope.
