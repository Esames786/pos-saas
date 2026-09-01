# Kashif Food — dine-in floors & tables

**Date:** 2026-08-30 · **Tenant:** kashiffood (id 348) · **Branch:** Kashif Food (id 1) · **Data only, kashiffood only**

## Final state (LIVE)
Two dine-in floors:
- **Ground Floor** — 16 tables numbered **21–36**.
- **First Floor** — 20 tables numbered **1–20**.

Capacity 4 each (owner can edit any table in Restaurant → Tables). Additive/master data — no code,
no migration, no other tenant touched.

## History (how we got here)
1. **First cut:** added a First Floor of 25 tables (1–25) beside the original messy Ground Floor
   (T1–T6 + a stray "21").
2. **Clean rebuild (Ground 1–16, First 1–20):** the Ground Floor had live test sessions/sales, so a
   clean rebuild first needed them cleared. Backup taken (kashiffood only), then
   `tenant:reset-transactions kashiffood`, then all branch-1 tables deleted and recreated as Ground
   Floor 1–16 + First Floor 1–20. Master data untouched, other tenants untouched.
3. **Ground Floor renamed to 21–36 (while LIVE):** with real orders in progress, the Ground Floor was
   renumbered +20 (1→21 … 16→36) by a pure `table_no` UPDATE — **no id changed, nothing removed**, so
   the open sessions/orders (which reference `restaurant_table_id`, not the number) stayed fully intact.
   First Floor left at 1–20.

## Rules going forward
- **Kashif Food is LIVE — no transaction resets.** Any table change is a `table_no` UPDATE only (never
  delete/recreate) so in-progress orders are never orphaned.
- Per-floor numbering; distinguished on the POS board by the floor header.

## Verification (post-rename)
`table_no` UPDATE only; `sales_orders`, `restaurant_table_sessions`, `sale_payments`, `shifts` all
unchanged; open sessions re-map to the new numbers (24 / 25 / 30 on Ground Floor) with the same ids.
