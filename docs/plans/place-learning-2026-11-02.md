# PLACE-LEARNING-1 — delivery areas get more accurate with every delivered order (2 Nov 2026)

## Why

Real customers now order on both restaurant websites (Kashif Food since 2 Nov, Khatri since 29 Oct). The owner asked
whether the system learns customers' areas and places, so that the website picks the right area more often over time.

Owner, 2 Nov: "suggested na ae bahlay lekin mere area mature hote rahen jese jese order barhte rahen area more
accurate hon". In other words: no "Suggested" list waiting for approval. The areas should mature on their own as
orders arrive.

## What exists today (old commits reviewed)

| Commit | What it built | What it leaves for this work |
|---|---|---|
| `033e06b4` … `7cda5646` (ONLINE-ORDERING-1 27–31/n) | `online-ordering:map-places` maps every society around a restaurant from OpenStreetMap. Only sure ones. Generic names ("Block 7", "Society", "DHA") are never a name on their own (`OnlineOrderingMapPlacesCommand::distinctive()`). | The same "is this name distinctive" rule. |
| `8d8d792d` DELIVERY-PLACES-1 | Real tables. Master `geo_area_places` holds platform places. Tenant `delivery_zone_places` holds the restaurant's own places, with `source` manual / **learned** / override, plus `orders_count` and `last_order_at`. `DeliveryPlaces::all()` is the one reader. The Delivery Places screen already has a "Learned from orders" filter and shows "N orders". | Columns and screen are **ready, but nothing writes `learned`** (checked: no writer in `app/`). |
| `fb6ba791` DELIVERY-RADIUS-1 | Fee by distance from the branch to the customer's pin. | The pin is what we learn from. |
| `7c21c38c` (36/n) | Device accuracy is stored. Rough (>200 m) locations show only the big society. | `web_meta.place_accuracy_m` and the 200 m line. |

Each website order already stores the following in `sales_orders.web_meta`:

- `area_id` / `area`: the area the customer chose and the restaurant delivered to.
- `lat` / `lng`: the pin.
- `place_accuracy_m`, `place`, `fee_from`, `distance_km`.

On prod, 2 Nov, there were 0 learned places. Khatri had 2 paid orders with good pins (±28 m, ±11 m). Kashif Food had
1 order with a pin and 1 order without.

## Design

1. **Evidence.** A new tenant table, `delivery_place_sightings`, gets one row per processed website order:
   `sales_order_id` (unique), `delivery_zone_id`, `name` / `name_key`, `lat` / `lng`, `accuracy_m`, and `skipped`
   (the reason nothing was learned).
2. **Which orders count.** Only orders that are delivery, **Paid** (or partly or fully returned, the report's
   population), and have a pin. Phone GPS must be within ±200 m. A pin placed by hand on the map has no accuracy figure
   and counts. Pick-up orders, orders with no pin, and rough GPS are recorded as skipped and never looked at again.
3. **The place's name** comes from OpenStreetMap at the pin, using the same cached lookup the website uses:
   - neighbourhood / residential / quarter, when that name is distinctive;
   - when it is generic ("Block 6"), the society in front of it ("PECHS Block 6");
   - otherwise the society alone, if distinctive;
   - otherwise no name, recorded as skipped.
4. **Maturing.** The learned place is recomputed from all its evidence every time a new order adds to it:
   - **area** = the area most deliveries for that name went to (a tie goes to the latest);
   - **point** = the median of the pins in that area, which resists one bad pin;
   - **1 order** → `kind = nearby`: the place counts for "nearest area" by distance only;
   - **2+ orders** → `kind = sure`: the name also picks the area, and the place shows in "Recommended near you".
   - `orders_count` and `last_order_at` are updated.

   No "Suggested" step. Each order makes the area a little more accurate.
5. **The admin always wins.**
   - A manual place, or a map place the restaurant moved, is never touched.
   - **Move** on a learned place makes it the admin's (`source = manual`), and learning stops changing its area.
   - **Remove** on a learned place hides it, so it does not come back with the next order.
   - A name that is already on the map list (`geo_area_places`) is not duplicated. The map already knows it, and the
     evidence is still kept.
6. **When.** The command `online-ordering:learn-places` runs hourly in the background. It is a dry run by default and
   scheduled with `--yes`, like the billing commands. It pauses about 1 s between OpenStreetMap lookups (their rule). It
   **never touches the POS**: no checkout, hold or pay path changes. The first run learns from the orders already paid.

Not in this change (offered later): a red mark in the POS Website orders window when the pin sits in another area than
the one chosen.

## Impact (system map)

- **Tables:**
  - new tenant `delivery_place_sightings` (FK `sales_orders`, `delivery_zones`);
  - writes `delivery_zone_places` (source `learned`);
  - reads `sales_orders.web_meta`;
  - reads master `geo_area_places` / `geo_areas`.
- **Screens:**
  - Online Ordering → Delivery Places: learned rows, Move / Remove behaviour;
  - the website area window, through `AreaLocator` / `DeliveryPlaces::all()`, unchanged code.
- **Commands / schedule:** new `online-ordering:learn-places`, hourly.
- **Reset:** `tenant:reset-transactions` wipes `delivery_place_sightings` with the sales. Learned places stay, like the
  other delivery places.
- **Tests:**
  - new `OnlineOrderingPlaceLearningMySqlTest`, covering: learn, mature to sure, majority area, skips, admin wins,
    dry run, idempotent, and the website locating by a learned name;
  - plus the existing `OnlineOrderingLocation`, `Setup` and `Storefront` tests, and `TenantResetFailClosed`.
