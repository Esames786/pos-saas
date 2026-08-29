# QUICK-REPORT-SEND-1 — POS "Quick Report" modal (permission-gated, unscoped, email/print/network)

**Status:** DESIGN / PLAN (nothing built yet — confirm, then build → test → deploy)
**Date:** 2026-08-27
**Author:** Claude
**For:** Khatri Biryani (and any tenant); LIVE tenant — additive only.

---

## 1. Goal

A trusted counter/POS user — **without** full Report Center screen access — can, from a button on the
POS screen, open a modal, tick which Sales-Report sections he wants (and optionally which categories /
items), pick a business date, and then **email it to the owner (A4 PDF)**, **print it here (thermal)**,
or **send it to a network thermal printer** — over the **whole tenant's data** (no terminal / order-type
restriction). The button is gated by a **dedicated permission** given only to trusted users.

**Output is IDENTICAL to Report Center** — the exact same thermal template and the exact same A4 PDF
document/engine. This modal changes nothing about *how* a report looks; it is purely a filter/selection
screen deciding *what* goes in (which sections + which categories/items/waiters/order-types), then hands
that to the unchanged renderers.

Confirmed decisions (owner, 2026-08-27):
- **Email = A4 PDF** (same document as the scheduled daily report).
- **Date = single business date**, default the current shift's business date, selectable.
- **Recipients = the daily-report recipients** (the 4 already on Khatri's `Daily Owner A4 Sales Report`
  schedule): `kashfgulzar@`, `abdullahshamsi849@`, `m.bilal.sham2007@`, `uit.mohsin95@` — one source of truth.

---

## 2. Spec (as described, restated)

- **Where:** a new **"Quick Report"** button on the POS screen (top-right, near the existing *Report*).
- **Permission:** shown/usable only to a user granted `tenant.pos.quick-report-send`. Trusted only,
  because it exposes ALL data.
- **Modal contents:**
  - **Section checkboxes (8):** Overview, Categories, Items, Waiters, Order Types, Order-Type Combos,
    Cancellations, Cash & Bank. (Only *Details/CSV-only* is excluded — it is not a thermal/A4 section.)
    Categories / Items / Waiters / Order Types carry a multi-select (default All); the other four are
    plain include-checkboxes.
  - **Date:** single business date (default = current shift business date).
  - Each of **Categories / Items / Waiters / Order Types** carries a **multi-select, defaulting to
    "All"**; Overview is a summary and has no sub-filter.
    - **Categories** (few) → all listed as a checkbox/chip list; find & tick; none = all.
    - **Items** (many) → **search-and-select** (typeahead over the on-page products payload — filtered,
      not re-loaded); chosen items as removable chips; **All items** toggle; none/All = all.
    - **Waiters** (few) → all listed as a checkbox list; default all.
    - **Order Types** (fixed set: dine_in / takeaway / delivery / quick_sale) → checkbox list; default all.
  - **No terminal / no order-type filter** — always the full tenant data (this is the trust point).
  - **Save my settings** — persist this user's section + filter choices as his default.
  - **Three actions:** Email to owner (A4 PDF) · Print here (thermal) · Send to network (thermal) + a
    network-printer picker.
- **Print format = thermal**, identical to Report Center's thermal output.
- **Purpose:** end-of-shift, a trusted user sends the category-/item-/waiter-wise + overview + order-types
  report to the owner or prints it — one click, no full Report Center.

---

## 3. Design

### 3.1 The trust boundary (the crux)
Report Center's `filters()` runs every report through
`UserDataScope::applyToReportFilters()` — a terminal/order-type-restricted operator can only ever see
his own counter's data. **The Quick Report modal deliberately BYPASSES UserDataScope**: it always
reports the whole tenant (all terminals, all order types). Therefore the *only* gate is the new
permission — it means "may see & send ALL sales data". We document this explicitly and give it only to
trusted users (exactly what the owner asked). No section-level split here — the trusted user gets the
five sections wholesale.

### 3.2 Permission
- New synthetic tenant permission **`tenant.pos.quick-report-send`** (spatie, guard `tenant`), seeded by
  a tenant migration + back-granted to Owner. Assigned to specific users via the Permission Center.
- The POS button (`@can`) + every modal endpoint is gated on it. No terminal-scope applied when it passes.

### 3.3 POS button + modal (Blade + JS)
- In `resources/views/tenant/pos/index.blade.php`, add a **Quick Report** button near *Report*, wrapped
  `@can('tenant.pos.quick-report-send')`.
- The POS page ALREADY loads, client-side, the full **categories** payload (`Category::with('children')`)
  and the full **products** payload (the grid + the POS search box use them). The modal reuses BOTH — **no
  new search endpoint**.
- Modal (Bootstrap): eight section checkboxes (Overview, Categories, Items, Waiters, Order Types,
  Order-Type Combos, Cancellations, Cash & Bank — Categories/Items/Waiters/Order-Types with a
  multi-select, the rest include-only); a single `<input type="date">` defaulting to the current
  business date; **Categories** (when ticked) = full checkbox/chip list of ALL categories (few) from the
  on-page category payload, find & tick; **Items** (when ticked) = a **search box** doing a client-side
  typeahead over the on-page products payload (products are many, but they're already in memory — just
  filtered, never re-loaded), adding chosen items as removable chips, plus an **All items** toggle; a
  **Save my settings** checkbox; a network-printer `<select>`; three action buttons.
- JS posts to the endpoints with `sections[]`, `date`, `category_ids[]`, `product_ids[]`, `all_items`,
  `printer_id` (for network), CSRF via the app's inline `'{{ csrf_token() }}'` pattern.

### 3.4 Backend — `PosQuickReportController` (new)
Routes (tenant, gated by `tenant.pos.quick-report-send`):
```
POST /pos/quick-report/email          → email(A4 PDF) to the daily-report recipients
GET  /pos/quick-report/print          → thermal print view (browser window.print)
POST /pos/quick-report/send-to-network→ queue a `report` print_job to a network printer
POST /pos/quick-report/save-settings  → persist this user's modal defaults
GET  /pos/quick-report/settings       → load this user's saved defaults (modal open)
```
All build filters as: `date_from = date_to = <date>`, branch = user's default branch (or all),
`category_ids`/`product_ids` from the modal — and **do NOT call UserDataScope** (full data). They reuse:
- **Email:** `SalesReportDocumentService::pdf($filters,$sections)` → `SalesReportMail(..., pdf, filename, sections)`
  → `Mail::to($recipients)->send()`. Recipients = the tenant's `report_schedules.recipient_emails`
  (the daily schedule), fallback `tenants.owner_email`.
- **Print here:** render `tenant.reports.center.print` in **thermal** mode (same view Report Center uses)
  → browser print. (Reuses the existing thermal template unchanged.)
- **Send to network:** the exact `sendToNetwork()` shape — `PrintJobFactory` `report` job with
  `EscPosPayloadService::buildReport($report)` — but with the unscoped filters + multi-select.

### 3.5 Multi-select = engine-level WHOLE-report filter (revised per owner) — additive, cascading
The owner wants the picks to **cascade**: choose a category (+ its sub-categories) and the ENTIRE
report — items (even "All items" = all items *within* those categories), waiters, order types,
overview, NET SALES, cash-bank — follows the selection, AND-composing across dimensions. That is
exactly the Report Center's own `category_id` behaviour (`isLineNarrowed` → order-level sections
measure only those lines), extended to multi-value.

`normalizeFilters` gains **`category_ids/product_ids/waiter_ids/order_types`** (arrays, default `[]`),
applied as `->when($f[…], whereIn/whereExists…)` in the four shared base queries (`salesBase`/
`linesBase`/`returnsBase`/`returnLinesBase`), with a `categoriesWithDescendants()` union so a parent
pulls in its children; `isLineNarrowed()` also fires on the arrays. **Purely additive & GUARANTEED safe
for the Report Center — it passes single-value filters and NEVER these arrays, so every new
`->when([])` is a no-op and its query/output is byte-identical; proven by the full report regression
(SalesReportEngine / ReportCenter* / Schedule / SendToNetwork / BusinessDate all green).** No existing
filter SQL was changed; no duplicate engine (which would drift out of sync).

### 3.6 Save user setting
New tenant table **`pos_quick_report_settings`** (`user_id` unique, `payload` json, timestamps). Payload =
`{sections:[…], category_ids:[…], product_ids:[…], all_items:bool}`. `save-settings` upserts; `settings`
returns it to pre-fill the modal. Per-user, additive, touches nothing else.

---

## 4. Reuse map (minimise new code)
| Need | Reused as-is |
|---|---|
| Report data | `SalesReportEngine` (overview/byCategory/byItem/byWaiter/byOrderType) + new multi-select |
| A4 PDF | `SalesReportDocumentService::pdf` |
| Email | `SalesReportMail` (pdfContent path) |
| Thermal browser print | `tenant.reports.center.print` (mode=thermal) |
| Network thermal | `EscPosPayloadService::buildReport` + `PrintJobFactory` `report` job |
| Recipients | `report_schedules.recipient_emails` (daily schedule) → fallback `owner_email` |

New: 1 controller, 1 permission, 1 settings table, engine multi-select, POS modal, routes.

---

## 5. Testing plan (MySQL + view)
1. **Permission gate:** without `tenant.pos.quick-report-send` → button hidden + endpoints 403; with it → 200.
2. **Unscoped:** a terminal-bound user WITH the permission gets **all-terminal** data (UserDataScope NOT applied) — assert totals equal the owner's all-terminal totals, not the user's terminal subset.
3. **Multi-select:** `category_ids=[A,B]` → only A+B rows; `product_ids=[x]` → only x; empty → all. Single `category_id` still works (Report Center regression).
4. **Email:** posts → a `SalesReportMail` with the A4 PDF attachment to the 4 recipients (Mail::fake assert).
5. **Send to network:** creates ONE `report` print_job with non-empty `raw_payload`, printer validated network.
6. **Save/load settings:** upsert + read-back round-trips the payload per user.
7. **Blade compile** of the POS view with the modal.

---

## 6. Deploy notes (additive, live-tenant safe)
- 1 tenant migration (permission seed + back-grant to Owner) + 1 tenant migration (`pos_quick_report_settings`).
- New tenant routes → `system:routes-sync` + **additive grant** of `tenant.pos.quick-report-send` to the
  intended user's role (or Owner) + clear tenant permission cache (the standing new-route gotcha).
- No POS/inventory/finance/sales writes anywhere — reports are read-only; only the per-user settings row is written.
- No change to existing Report Center behaviour (engine change is additive + regression-tested).

---

## 7. Decisions (owner-confirmed 2026-08-27)
1. **"Print here"** = browser print to the LOCAL receipt printer (like Report Center *Print Selected
   Thermal*). ✅
2. **Sections = 8** (Overview, Categories, Items, Waiters, Order Types, Order-Type Combos, Cancellations,
   Cash & Bank). *Details/CSV-only* excluded (not a thermal/A4 section). ✅
3. **Multi-select (default All)** on **Categories, Items, Waiters, Order Types**; the other four are plain
   include-checkboxes. → engine also gains `waiter_ids[]` + `order_types[]` (multi), alongside
   `category_ids[]` + `product_ids[]`. ✅
4. **Email = A4 PDF** to the **daily-report 4 recipients**; **Date = single business date** (default
   current shift). **Unscoped** (all terminals / all order types) behind the permission. ✅
5. Button **"Quick Report"** next to the POS *Report* button. ✅
