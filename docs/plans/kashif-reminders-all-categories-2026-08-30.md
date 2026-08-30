# Kashif Food — reminders for ALL categories (incl. counter items)

**Date:** 2026-08-30 · **Tenant:** kashiffood · **Data only, kashiffood only** · **Driver:** owner

## Ask
Owner wants a **reminder ticket for every item**, not just BBQ/Fastfood. Today reminders fire only for
the kitchen-station categories (BBQ + Fastfood); the 5 **counter** categories have no reminder rule, so
a counter-only order prints no reminder.

## Current state
`category_printer_mappings` reminder rules: each of the 4 terminals has 25 reminder rules
(BBQ + Fastfood categories) → **its own counter printer** (Delivery→T1/.100, DTQ 1→T2/.101,
DTQ 2→T3/.102, DTQ Floor→T4/.103). Template row: `print_role=reminder, order_type=all,
reminder_confirm_on_addition=0, is_active=1`, terminal-pinned.

The 5 counter categories have **0** reminder rules:
- Singaporean Rice (25), Chicken Biryani (26), Beverages (27), Raita & Salad (28), Extras (29).

## Change
For **each terminal**, add a reminder rule for **each of the 5 counter categories** pointing at that
terminal's **own counter printer** (the same printer its existing reminders use) — mirroring the
BBQ/Fastfood reminder setup. **5 categories × 4 terminals = 20 new reminder rules.**

Result: every item — counter, BBQ or Fastfood — produces a reminder ticket at the punching terminal's
counter printer. (Counter items therefore print their KOT and a reminder both at the counter; that is
the owner's intent — one reminder per item.)

## Safety
- Additive config only (new `category_printer_mappings` rows). No code, no migration, no schema change.
- Idempotent: only inserted where a (terminal, category, reminder) rule doesn't already exist.
- Terminal-pinned to each terminal's own counter printer — respects the existing per-terminal routing;
  a recalled order's reminder still follows the current operator's terminal (RECALL-REPRINT-TERMINAL-1).
- Master data → survives a transaction reset (Kashif is LIVE — no reset anyway).

## Rollback
Delete the 20 added reminder rows (counter categories, print_role=reminder).
