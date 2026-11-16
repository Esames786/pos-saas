# PLACE-NAME-NEAR-1: a place name picks the area only near the pin (9 Nov 2026)

**Owner, 8–9 Nov:** "teekh hai esa kardo … location na mango forcefully, agar na de to select to karega, masla nahi,
tum 1 km wala teekh kardo".

## The problem (prod, read-only check 8 Nov)

The website's "use my location" box (`/order/locate` → `AreaLocator::locate`) picks the area **by name first**: if every
word of a known place's name is in the map's address for the pin, that place's area wins, however far away the place is.
OpenStreetMap files big stretches under one suburb name, so a far-away place wins:

| Map place (its area) | Customers it caught | Distance from the pin |
|---|---|---|
| Gulshan-e-Kaneez Fatima Society (Scheme 33) | Sui Gas Society / Allama Shabbir Ahmed Usmani Rd: 2 orders in one restaurant, 1 in another (chose Gulshan Block 6 / Johar Block 7) | 1.5–2 km |
| Gulshan-e-Shamim (FB Area Block 8) | Aga Khan Hospital for Women (chose Block 7), Yasinabad (chose Block 9) | 0.6–0.9 km |

Learning from deliveries (PLACE-LEARNING-1) cannot undo this: the longest matching name wins, and a name already on
the map list is never learned.

## The change

In `AreaLocator::byName`, a **place** (map list, the restaurant's own, or learned, kind `sure`) decides the area by its
name only when its point is within **1 km** (`NAME_NEAR_KM`) of the pin. Otherwise the pin goes on to the next name that
matches, then to the nearest area, as today.

- An area's own name (for example "FB Area Block 8") is unchanged: areas are big and have only one centre point.
- A place without a point (some aliases, places the restaurant typed) is unchanged: there is no distance to measure.
- The "closed area" check uses the same rule, so a far-away name no longer says "we don't deliver to X".
- Nothing else changes: the fee, the customer's own choice, the POS, learning. The customer still picks or changes the
  area; a customer who doesn't share a location still picks it from the list (the owner does not want location forced).

## What-if on prod's real orders (read-only, same 39 + 4 pins)

| Restaurant | Today | With 1 km | Broken |
|---|---|---|---|
| Restaurant A (39 pins) | 33 | 35 | 0 |
| Restaurant B (4 pins) | 3 | 4 | 0 |

0.75 km gave the same result; 0.5 km fixed one more but broke one, so the rule uses 1 km.

## Impact (system map)

- **Screen/route:** `website.order.locate`, `website.domain.locate` (the order page's location box). No admin screen.
- **Tables read (no writes):** `delivery_zones`, `delivery_zone_places`, master `geo_area_places`, `geo_areas`.
- **No migration, no permission, no switch.** It only corrects a suggestion the customer can change. A Master switch
  would only keep a known wrong answer for some tenants.
- **Tests:** `tests/MySql/OnlineOrderingLocationMySqlTest.php` (new test + existing ones),
  `OnlineOrderingPlaceLearningMySqlTest.php`, `OnlineOrderingCheckoutMySqlTest.php`, `OnlineOrderingStorefrontMySqlTest.php`.

## After deploy

Re-run the read-only what-if with the real `AreaLocator::locate` on prod: expect 35/39 and 4/4.
