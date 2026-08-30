# Kashif Food — First Floor + 25 dine-in tables

**Date:** 2026-08-30 · **Tenant:** kashiffood (id 348) · **Branch:** Kashif Food (id 1) · **Data only, kashiffood only**

## Goal
Add a **First Floor** to Kashif Food with **25 dine-in tables numbered 1–25**, ready for the go-live
seating. Additive master/config data — no code, no migration, no other tenant touched.

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
