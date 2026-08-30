# POS — a recalled order's reprints follow the CURRENT operator's terminal

**Date:** 2026-08-30 · **Driver:** Kashif Food owner · **Scope:** cloud POS printing (shared service — all tenants)

## Problem
`PrintRoutingService` keys every route on **`$sale->terminal_id`** — the terminal that CREATED the
sale. That is right for a sale worked start-to-finish at one counter. But when a multi-terminal
cashier **recalls another counter's saved order** and reprints its **old KOT / receipt / reminder** (or
"Send to network"), those print at the **original** counter (T3), not the operator's own (T2) — even
though the operator is physically at T2 and asked for the print.

New items on a recalled order already print correctly at the operator's counter (Hold / Review & Pay
stamp the POSTED terminal — POS-RECALL-TERMINAL-1), and Preview Bill uses the live form terminal. The
gap is only the **reprint of an already-saved order's documents** while it still carries its original
terminal_id.

## Owner's requirement
> "Recall karne ke baad jo bhi print karun — old KOT / receipt / reminder — mere counter se ho."

Prints must follow the **current operator's terminal**; the sale's **attribution / cash / closing must
stay with the original terminal** (do NOT re-stamp the sale).

## Why not the obvious fixes
- **Mutating `$sale->terminal_id` in memory is UNSAFE** — `PrintJobService` calls
  `$sale->forceFill([...])->save()` (last_receipt/kot_printed_at), which would persist the dirtied
  terminal_id and corrupt the sale's attribution.
- **Re-stamping the sale** would move its cash/closing to the new terminal (accounting shift) — rejected.

## Decision — a routing terminal OVERRIDE (no sale mutation)
Add an optional `?int $terminalOverride = null` to the routing methods. When null (every existing
caller), behaviour is unchanged (`$sale->terminal_id`). When provided, printer resolution uses the
override terminal — but the sale row is never touched, so attribution stays put.

**Effective terminal = `$terminalOverride ?: (int) $sale->terminal_id`**, applied in:
- `receiptPrinter()`, `defaultKotPrinter()` — receipt/KOT printer per terminal.
- `mappedPrinterIds()` / `applyTerminalPrecedence()` — the terminal-pinned COUNTER category rules
  (so counter items follow the current terminal; BBQ/Fastfood category rules are terminal-agnostic
  and keep going to their stations).
- `kotRoutesForSale()`, `reminderRoutesForSale()`.

Thread the override:
- `PrintJobService::queueReceipt/queueKot/planRemindersForKotJobs` accept the override and pass it on
  (queueKot already carries a `$terminalId`; reuse it for routing too, not just the job tag).
- Controllers `PrintJobController::queueReceipt/queueKot/confirmReminders/reprintReminder` read
  `request('terminal_id')` (the POS already sends the operator's active `#terminal_id`) — validated
  against the operator's allowed terminals via UserDataScope — and pass it as the override.

## Safety
- Backward compatible: every existing call passes nothing → `$sale->terminal_id` as today.
- The sale row is never mutated; cash/shift/closing attribution unchanged.
- Category routing intact: BBQ→BBQ, Fastfood→Fastfood for all; only the per-terminal COUNTER rules
  and the receipt/reminder-to-counter follow the override.
- Terminal validated to the operator's own bound terminals (no printing to a foreign branch).

## Test
- Unit: routing for a sale with terminal T3 + override T2 → counter items + receipt resolve to T2's
  printer; Chinese/BBQ still resolve to Fastfood/BBQ. Without override → T3 (unchanged).
- PosFrontendRegression green; existing printing tests green.

## Rollback
Drop the override params; routing returns to `$sale->terminal_id` only.
