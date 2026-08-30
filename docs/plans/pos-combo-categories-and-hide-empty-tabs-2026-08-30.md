# POS — categorise deals (Al-Faham / Midnight / Platters…) + hide empty category tabs

**Date:** 2026-08-30 · **Driver:** Kashif Food owner · **Scope:** cloud POS · **Additive — no architecture change**

## Two asks (share one root: the category strip)
- **#4 Bifurcate deals:** all 41 combos lump under one "Deals" tab. The owner wants them grouped —
  **Al-Faham**, **Midnight Deals**, **Platters**, **Deals** — as their own tabs, without disturbing the
  structure.
- **#3b Hide empty tabs:** the POS shows every active parent category as a pill, so "Al-Faham
  Components" (only hidden combo-filler products) renders as an empty tab ("No products found").

## Why it's structure-safe
Combos gain an **optional** `category_id` — exactly the additive pattern products already use. Nothing
existing changes: a combo with no category still shows under "Deals" and "All" as today; a category
with no visible content simply stops rendering a pill. No table renamed/dropped, no relation removed.

## Change

### 1. Data model (additive)
- Migration: `combos.category_id` — nullable, indexed, FK to `categories` `nullOnDelete`.
- `Combo` model: add `category_id` to fillable + `category()` belongsTo.

### 2. Combo → category assignment (data, kashiffood)
- Create deal sub-categories in the existing `categories` table (top-level, active): **Al-Faham**,
  **Midnight Deals**, **Platters** (+ keep a plain **Deals** for the rest, or leave those uncategorised).
- Assign each combo its category by its code family:
  - `KF-PLAT-ALFAHAM-*` → Al-Faham
  - `KF-PLAT-*` / `KF-RKP-*` (platters/rice-kebab) → Platters
  - midnight combos → Midnight Deals
  - `KF-DEAL-*` → Deals (or leave null → the "Deals" pill)

### 3. POSController
- Load combos with their category; **combosPayload carries `category_id`**.
- Compute the set of parent categories that actually have **grid-visible products OR combos** (self or
  any child). Pass only those as `$categories` (the pills). → **#3b**.
- A deal sub-category has combos (no products) → it IS in that set → its pill shows. → **#4 pills**.

### 4. POS view (index.blade.php)
- Pills already `@foreach($categories …)` — now the list is pre-filtered, so empty tabs vanish and deal
  tabs appear. No markup change beyond that.
- Combo filter (`renderProducts`): a combo shows when the view is **All** or **Deals** (unchanged) **OR**
  its `category_id` matches the selected parent/child category. → **#4 grid**.

### 5. Admin (Combo create/edit)
- Add a **Category** dropdown (optional) to the combo form + controller validation
  (`category_id nullable exists:categories,id`), so the owner can re-file a deal later.

## Safety
- Additive nullable column; every existing combo (category_id null) behaves exactly as today.
- Non-deal categories that have visible products are unaffected; only truly-empty tabs are hidden.
- No change to pricing, KOT routing, combo components, or order flow.
- Master data → survives a reset (Kashif is LIVE — no reset anyway).

## Test
- Migration applies additively; Combo model relation resolves.
- POS renders: deal sub-category pills show their combos; "All" + "Deals" still show every combo;
  empty product-only category (Al-Faham Components) pill is gone.
- PosFrontendRegression + combo tests green.

## Rollback
Null out `combos.category_id`, drop the column, revert the POSController/view/form edits; the single
"Deals" tab returns and all active categories show again.
