# Kashif Food — dine-in floors & tables (Ground 1–16, First 1–20)

**Date:** 2026-08-30 · **Tenant:** kashiffood (id 348) · **Branch:** Kashif Food (id 1) · **Data only, kashiffood only**

## Goal (FINAL)
Two clean dine-in floors for go-live:
- **Ground Floor** — 16 tables numbered **1–16**.
- **First Floor** — 20 tables numbered **1–20**.

Per-floor numbering (each floor restarts at 1); distinguished on the POS board by its floor header.
Capacity 4 each (owner can edit any table later in Restaurant → Tables). Additive master/config data —
no code, no migration, no other tenant touched.

> First cut (superseded): a First Floor of 25 tables (1–25) alongside the original messy Ground Floor
> (T1–T6 + a stray "21"). Revised to the clean 16/20 above.

## How applied
The Ground Floor had 3 live test sessions + 4 test sales on its tables, so a clean rebuild first
needed those cleared. Sequence (backup taken first, kashiffood only):
1. `tenant:reset-transactions kashiffood` — cleared the test sessions/sales (master data untouched).
2. Deleted all 32 branch-1 tables (now unreferenced), recreated Ground Floor 1–16 + First Floor 1–20.

## Current state (before)
- Floors: **1** — "Ground Floor" (id 1, active).
- Tables: 7 — T1–T6 (cap 4) + one stray "21" on Ground Floor.
- `restaurant_tables` / `restaurant_floors` have **no unique index** on `table_no` / floor `code`
  (only PRIMARY on id), so adding tables "1".."25" cannot collide with existing rows.

## Change
1. **New floor** `restaurant_floors`: `name = "First Floor"`, `code = "1F"`, `branch_id = 1`,
   `status = active`, `sort_order = 2` (after Ground Floor).
2. **25 tables** `restaurant_tables` on the new floor: `table_no = "1" … "25"`, `branch_id = 1`,
   `restaurant_floor_id = <new floor id>`, `capacity = 4` (matches existing default), `status =
   available`, `sort_order = 1..25`. Reservation fields left NULL.

## Notes / decisions
- **Numbering** is plain `1..25` (as requested), not the `T1..` prefix the Ground Floor uses.
- **Capacity 4** by default (owner can edit any table later in Restaurant → Tables).
- Ground Floor and its tables are left as-is (not renamed, not removed). The stray "21" can be
  cleaned up separately by the owner if unwanted.
- This is master data → survives `tenant:reset-transactions` (a reset only sets table `status` back to
  `available`).

## Apply
Direct inserts on the kashiffood tenant DB (single tenant, guarded activate). Verify: 1 new floor,
25 new tables numbered 1–25, all available, on branch 1.

## Rollback
Delete the "First Floor" row and its 25 tables (no sessions reference them yet).
