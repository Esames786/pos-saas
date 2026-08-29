# POS Table Reservation — mark reserved (colour + notes + who reserved) + attach a customer

**Status:** RESEARCH / DESIGN (nothing built)
**Date:** 2026-08-28
**Scope:** Dine-in Table Workspace (the `table-board` tiles). Additive.

---

## 1. What already exists (research findings)

| Thing | State | Meaning for us |
|---|---|---|
| `restaurant_tables.status` enum | **already includes `reserved`** (`available, occupied, reserved, bill_requested, cleaning, inactive`) — [migration :30](../../database/migrations/tenant/0001_01_01_000011_create_restaurant_tables.php#L30) | No schema change for the STATUS itself |
| Board status source | when there is **no active session**, the tile uses **`$table->status`** ([table-board.blade :30](../../resources/views/tenant/pos/partials/table-board.blade.php#L30)) | A `reserved` table will already render a **"Reserved"** chip |
| Tile colour classes | `.restaurant-table-tile.available/.occupied/.bill_requested` have a coloured left-border; **`.reserved` has NO colour yet** ([index.blade :109-111](../../resources/views/tenant/pos/index.blade.php#L109)) | Add one CSS line for the reserved colour |
| Reservation details (who / phone / time / note) | **No columns** on `restaurant_tables` | Add nullable columns |
| Reserve UI | **None** — an available table only shows *Open Table*; the *Manage Tables* edit can set status but that's the admin CRUD, not the quick board | Add a *Reserve* action on the tile |
| Customer search / attach | **Already built** — `#customerModal`, the `/ajax/customers` lookup ([routes :144](../../routes/tenant.php#L144)), a hidden `#customer_id`, and quick-store | **Reuse it** to attach a customer to a reservation |

**Bottom line:** the status value is already legal and the board already renders it — we only need (a) a few nullable columns for the details, (b) a Reserve/Un-reserve action, (c) tile UI + a colour, and (d) wiring the existing customer picker in.

---

## 2. "Can we also attach a customer?" → YES, reuse what POS already has

The POS customer picker (search by phone/name over `/ajax/customers`, pick an existing customer with
their saved addresses, or quick-create a new one) is exactly the modal in your screenshot. The Reserve
form reuses the SAME search to pick a customer and stores `reserved_customer_id`. The reserved tile +
its details then show that customer's name / phone (and address if present). A walk-in with no record
can instead just be typed as a name + phone on the reservation — both paths supported.

---

## 3. Design

### 3.1 Schema — one additive tenant migration
Add to `restaurant_tables` (all nullable; `reserved` status already exists):
- `reserved_customer_id` (FK → customers, nullOnDelete) — the attached customer (optional)
- `reserved_name`, `reserved_phone` (string) — for a walk-in with no customer record
- `reserved_for` (datetime) — the booking time
- `reservation_note` (text) — free details
- `reserved_by_user_id` (who marked it), `reserved_at` (timestamp)

### 3.2 Backend — `RestaurantTableController` (or a small ReservationController) + routes
- `POST /restaurant/tables/{table}/reserve` — only if the table is **available** (no open session):
  set `status='reserved'` + the reservation fields. Validates `reserved_customer_id` exists (if given),
  `reserved_for` a datetime, note length.
- `POST /restaurant/tables/{table}/unreserve` — `status='available'`, clear the reservation fields.
- `GET  /restaurant/tables/{table}/reservation` — return the details for the "view" click (or embed
  them in the board payload so no extra call is needed).
- **Permission:** new `tenant.restaurant.tables.reserve` (granted to Owner + table-managing roles).
- **On Open Table for a reserved table:** opening a session flips it to `occupied` (existing flow); we
  clear the reservation fields at the same time and optionally **carry the reserved customer** onto the
  new session's order (so the booking's customer is already attached).

### 3.3 Frontend — the `table-board` tile
- **Available tile:** add a **Reserve** button beside *Open Table* → opens a **Reserve modal**:
  - customer: reuse the customer search (attach an existing customer) OR type name + phone,
  - date/time picker (`reserved_for`),
  - a note field,
  - Save → POST `reserve` → the board re-renders (`refreshTableBoard()`), tile turns reserved.
- **Reserved tile:** gets the `.reserved` colour; shows the **Reserved** chip + reserved-for
  name + time; a **Details** click shows who reserved it, phone, time and the note; plus **Open Table**
  (guest arrived → opens the session, carries the customer) and **Cancel Reservation** (un-reserve).
- **CSS:** one line — e.g. `.restaurant-table-tile.reserved { border-left: 6px solid #a855f7; }`
  (purple) or amber `#f59e0b`.

### 3.4 Reuse map (little new code)
| Need | Reuse |
|---|---|
| Customer search / attach | `#customerModal` flow + `/ajax/customers` + quick-store (existing) |
| Reserved status render | board already renders `$table->status` when no session |
| Board refresh after reserve | `refreshTableBoard()` / `GET /api/pos/table-board` (existing) |
| Reserved colour | one CSS rule (`.restaurant-table-tile.reserved`) |

New: 1 migration (nullable columns) + reserve/unreserve/details actions + 1 permission + a Reserve
modal + tile buttons + CSS. **Additive** — available/occupied/bill-requested flows untouched.

---

## 4. Feasibility & effort
**Fully feasible, moderate effort.** Nothing structural is missing — the status value, the board
render, and the customer picker already exist; we add the detail columns, the reserve/un-reserve
actions, and the tile UI. No change to sessions, orders, payments or the existing board statuses.

---

## 4b. There are TWO different table boards — they have diverged (owner flagged)

| | **POS "View Tables"** (in-POS) | **Restaurant → Table Board** (`/restaurant/board`) |
|---|---|---|
| View | `pos/partials/table-board.blade.php` (tiles) | `restaurant/table-board.blade.php` (cards) — separate, older |
| Controller | `POSController@tableBoard` (partial, AJAX refresh) | `RestaurantTableSessionController@board` (full page) |
| Colours | available=green, occupied=**orange**, bill=blue | available=green, occupied=**red**, reserved=**yellow** (different) |
| Actions on OPEN table | Open Table | Open (Guests/Waiter/Notes modal) |
| Actions on OCCUPIED table | Continue · Bill Preview · Split Bill · Held Orders · Move | **only Close · View** |
| Permissions | `tenant.restaurant.table-sessions.*` (dots) | `tenant.restaurant-table-sessions.*` (dashes) — a different set |

**Why:** they are two independently-built screens; the POS one has been actively developed (Continue /
Bill Preview / Split / Held / Move) while the standalone board stayed basic. That's the divergence the
owner sees — and exactly why a reservation added to one wouldn't appear on the other.

### Recommendation — unify to ONE source of truth
Make `/restaurant/board` **reuse the POS tile partial** (`pos/partials/table-board.blade.php`) and
**share the table-board JS** (open / continue / bill-preview / split / held / move — currently inlined
in the POS page) by extracting it into one script both pages load. Then:
- Both boards show the **same options, same colours**, and the **Reservation feature lands once**.
- No more drift between the two.
- The standalone board keeps its full-page chrome + branch/terminal picker; only the tiles + JS are shared.

**Effort:** moderate — the real work is lifting the POS table-board JS handlers into a shared file
(they currently rely on the POS page's other globals, so a careful extraction), plus aligning the
`restaurant-table-sessions` (dash) vs `restaurant.table-sessions` (dot) permission checks.

**Scope options for the owner:**
- **(1) Reservation only, on the POS board** — smallest; the standalone board stays basic for now.
- **(2) Reservation + unify** — build reservation into the shared partial AND make `/restaurant/board`
  use it, so both boards match (recommended, but a bigger change).

---

## 5. Decisions (owner-confirmed 2026-08-28)
1. **Customer = BOTH** — pick an existing customer from the book (reuse the customer search) OR type a
   walk-in name + phone. ✅
2. **Reservation time = YES** — a date/time field (`reserved_for`) **plus** a free note. ✅
3. **Reserved tile colour = purple `#a855f7`.** ✅
4. **Who can reserve = any dine-in operator.** → gate the reserve/unreserve endpoints in-controller on
   the EXISTING `tenant.restaurant.table-sessions.open` permission (no NEW permission needed); add the
   `tenant.restaurant.tables.reserve` route prefix to `EnsureRoutePermission`'s allow-list so the
   route-name check is skipped and the in-controller gate governs. ✅
5. **On Open Table of a reserved table:** carry the reserved customer onto the new order automatically. ✅

**Deploy note:** 1 additive tenant migration (nullable columns) → needs `deploy.sh` (tenant migrate);
no new permission to sync/grant (reuses table-open).

### Scope = Reservation + unify, delivered in TWO phases (owner-confirmed "unify")
- **Phase 1 (build now):** the Reservation feature built into the **shared POS tile partial**
  (`pos/partials/table-board.blade.php`) — migration + reserve/unreserve/details + customer attach +
  purple tile. Self-contained, low-risk, ships value; and it is exactly what Phase 2 reuses, so no
  rework.
- **Phase 2 (follow-up):** point `/restaurant/board` at the same partial + extract/share the POS
  table-board JS so both boards match. Bigger, live-POS-sensitive refactor — its own careful pass.
