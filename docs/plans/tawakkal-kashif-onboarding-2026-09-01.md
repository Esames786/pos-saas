# Tawakkal + Kashif Foods — new tenant onboarding (read-before-build)

**Status:** DESIGN ONLY. Nothing seeded, nothing provisioned, no code written.
**Date:** 2026-09-01 · **Author:** from the owner's menu images (6 screenshots)
**Reference tenant:** **Kashif Food (#348)** — for roles, screen permissions and data scope.
**Explicitly NOT the reference:** Khatri Biryani. (Kashif Food was built off Khatri and that
produced role mistakes the owner then had to correct by hand. This time the reference is Kashif
Food **as it is live today**, not as its seeder originally wrote it — see §5.)

---

## 1. What the client is

One tenant, **two branches**, one owner:

| # | Branch | Menu |
|---|---|---|
| 1 | **The Kashif Foods** | The red card — Singaporean Rice, Biryani, Pulao, BBQ, Rolls, Platter + 6 deals |
| 2 | **Tawakkal Biryani** | The black-and-white card — Biryani, Chana Pulao, Beef Pulao, Beverages |
| — | *both* | **Cherry Crunch** ice cream |

**Modules: POS + Finance only. No inventory.** So every product is service-based exactly the way
Kashif Food's already are (`is_stock_tracked = 0`, consumption `none`) — no stock balances, no
purchase flow, no goods receipts, no negative-stock questions.

**Users: 3 total** — 1 Owner, 1 operator for Kashif Foods, 1 operator for Tawakkal.

---

## 2. ⚠️ The decision that has to be made before anything is seeded

**A tenant has ONE product book, and the POS grid is not branch-aware.**

Verified in `POSController::index()` on today's code:

| Thing | Branch-scoped? | Evidence |
|---|---|---|
| Combos (deals) | **Yes** | `combos.branch_id`, POS filters `whereNull('branch_id')->orWhere('branch_id', $selected)` |
| Waiters | **Yes** | same idiom |
| Delivery channels / riders | tenant-wide | — |
| **Categories** | **No** | `categories` table has no `branch_id` column |
| **Products** | **No** | `products` table has no `branch_id`; the grid query filters only `status`, `is_sellable`, `is_pos_visible` |
| Prices | **Yes, per branch** | `product_branch_prices` (branch + optional variant → `selling_price`) |

**Consequence if we do nothing:** the Tawakkal cashier's POS shows all 19 BBQ items, all 6 rolls and
the Classic Platter; the Kashif Foods cashier sees Chana Pulao and Beef Pulao. Both counters get a
menu that is not theirs, and both KOT/report screens fill with the other branch's categories.

### Three ways out

| | Approach | Cost | Result |
|---|---|---|---|
| **A** | Seed everything, live with it | zero | Both menus on both screens. Workable only if the counters are told to ignore half the grid — realistically it causes wrong punches. |
| **B** | Prefix every category (`KF · BBQ`, `TB · Beef Pulao`) | zero code | Still both menus on both screens, just labelled. Grid clutter stays. |
| **C** | **Add `branch_id` to `categories` (nullable) and filter the POS grid by it — the pattern combos and waiters already use** | one additive migration + ~10 lines in `POSController` + a guard test | Each counter sees only its own menu; a category with `branch_id = NULL` (Cherry Crunch, Beverages) shows on both. |

**Recommendation: C.** It is not a new idea — it is the same `whereNull('branch_id') OR
branch_id = ?` idiom that is already in this controller three times. It is additive (existing
single-branch tenants keep `NULL` and behave exactly as now), and it is the only option that
gives each counter its own menu. Products follow their category, so no `products.branch_id` is
needed.

**Owner's call needed.** Everything below is written so it works under any of the three.

### Where prices collide

Only these names exist on both cards at different prices. Under option C they are simply two
separate products in two branch-scoped categories; under A or B they need distinct names:

| Item | Kashif Foods | Tawakkal |
|---|---|---|
| Sadi Biryani | 200 | 180 |
| Sadi Biryani (1 KG) | 400 | 340 |
| Chicken Biryani | 280 | 180 (Single) |
| Chicken Biryani (1 KG) | 550 | 440 |

Tawakkal also sells sizes Kashif Foods does not (Half KG) and vice versa (6-pcs family pack), so
these are genuinely different menu lines, not one product with two prices.

---

## 3. The catalogue, exactly as the cards read

Prices are transcribed verbatim. Anything I could not read with certainty is marked **⚠**.

### 3.1 Branch 1 — The Kashif Foods (45 items)

**Singaporean Rice** (5)
| Item | Price |
|---|---|
| Singaporean Rice | 500 |
| Singaporean Rice (Large) | 950 |
| Singaporean Rice (Family Pack Small) | 2350 |
| Singaporean Rice (Family Pack Large) | 3450 |
| Extra Sauce | 130 |

**Singaporean Rice Khas** (2)
| Item | Price |
|---|---|
| Singaporean Rice Khas (2 Persons) | 1550 |
| Singaporean Rice Khas (4 Persons) | 2500 |

**Chicken Biryani** (6)
| Item | Price |
|---|---|
| Sadi Biryani | 200 |
| Sadi Biryani (1 KG) | 400 |
| Chicken Biryani | 280 |
| Chicken Biryani (1 KG) | 550 |
| Chicken Biryani (6 Pcs Family Pack) | 1600 |
| Extra Piece | 120 |

**Chicken Pulao** (6)
| Item | Price |
|---|---|
| Sada Pulao | 200 |
| Sada Pulao (1 KG) | 400 |
| Chicken Pulao | 260 |
| Chicken Pulao (1 KG) | 500 |
| Chicken Pulao (6 Pcs Family Pack) | 1520 |
| Extra Piece | 120 |

**BBQ** (19)
| Item | Price | | Item | Price |
|---|---|---|---|---|
| Chicken Tikka (Chest) | 450 | | Chicken Malai Kabab | 550 |
| Chicken Tikka (Leg) | 420 | | Beef Dhaga Kabab (Fry) | 550 |
| Chicken Malai Tikka (Chest) | 500 | | Beef Dhaga Kabab | 500 |
| Chicken Malai Tikka (Leg) | 480 | | Beef Seekh Kabab | 500 |
| Chicken Bihari Tikka (Chest) | 460 | | Beef Bihari Boti | 550 |
| Chicken Bihari Tikka (Leg) | 430 | | Chandan Kabab | 500 |
| Chicken Malai Boti | 550 | | Paratha (Small) | 60 |
| Chicken Shahi Chatakh | 580 | | Paratha (Large) | 120 |
| Chicken Boti Boneless | 500 | | | |
| Chicken Dhaga Kabab | 500 | | | |
| Chicken Reshmi Kabab | 500 | | | |

**Roll** (6)
| Item | Price |
|---|---|
| Chicken Chatni Roll | 220 |
| Chicken Mayo Garlic Roll | 250 |
| Chicken Malai Boti Roll | 250 |
| Chicken Malai Boti Garlic Roll | 280 |
| Beef Boti Chatni Roll | 240 |
| Beef Boti Mayo Garlic Roll | 270 |

**Classic Platter** (1) — **2300**, "3 to 4 persons"
Contents printed on the card: Tikka chest, Shashlik stick, Malai boti, Shahi Chattekh,
Reshmi kabab, Seekh kabab **and Rice**.
⚠ **"Shashlik stick" is not on the BBQ list** and the platter's rice is unnamed. See Q4.

### 3.2 Branch 2 — Tawakkal Biryani (20 items)

**Chicken Biryani** (5)
| Item | Price |
|---|---|
| Sadi Biryani | 180 |
| Sadi Biryani (1 KG) | 340 |
| Chicken Biryani Single | 180 |
| Chicken Biryani (Half KG) | 220 |
| Chicken Biryani (1 KG) | 440 |

**Chana Pulao** (3)
| Item | Price |
|---|---|
| Chana Pulao (1½ Pao) | 120 |
| Chana Pulao (Half KG) | 160 |
| Chana Pulao (1 KG) | 320 |

**Beef Pulao** (4)
| Item | Price |
|---|---|
| Beef Pulao Sada | 200 |
| Beef Pulao Single | 250 |
| Beef Pulao (Half KG) | 350 |
| Beef Pulao (1 KG) | 700 |

**Beverages** (8)
| Item | Price |
|---|---|
| Soft Drink 300ml | 80 |
| Soft Drink 500ml | 110 |
| Soft Drink 1 Ltr | 150 |
| Soft Drink 1.5 Ltr | 180 |
| Soft Drink Jumbo | 240 |
| Mineral Water (Small) | 50 |
| Mineral Water (Large) | 100 |
| Raita | 50 |

*(Raita sits under Beverages on the card. Kept there unless the owner says otherwise.)*

### 3.3 Both branches — Cherry Crunch (4)

| Item | Price |
|---|---|
| Cherry Crunch (Cup) | 120 |
| Cherry Crunch (250 g) | 280 |
| Cherry Crunch (Half Pack) | 550 |
| Cherry Crunch (Full Pack) | 1100 |

Seeded with **no branch** (visible on both) under option C.

**Total: 69 products.**

---

## 4. Deals — Kashif Foods branch only (6 combos)

Combos carry `branch_id` natively, so these bind to branch 1 with no code change.

| Deal | Price | Components |
|---|---|---|
| Deal 1 | 400 | 2 × Chicken Chatni Roll |
| Deal 2 | 560 | 1 Chicken Biryani (reg) + 1 Chicken Chatni Roll + 1 Drink 500ml |
| Deal 3 | 760 | 1 Chicken Biryani (reg) + 1 Chicken Tikka (Leg) + 1 Drink 500ml |
| Deal 4 | 810 | 1 Singaporean Rice (reg) + 1 Chicken Mayo Roll + 1 Drink 500ml |
| Deal 5 | 840 | 1 Chicken Biryani (reg) + 1 Singaporean Rice (reg) + 1 Drink 500ml |
| Deal 6 | 950 | 1 Singaporean Rice (reg) + 1 Chicken Tikka (Leg) + 1 Drink 500ml |

**The arithmetic checks out**, which also settles what "(reg)" and "Drink 500ml" mean:

```
Deal 2   280 + 220 + 110 = 610  → 560   (−50)
Deal 3   280 + 420 + 110 = 810  → 760   (−50)
Deal 4   500 + 250 + 110 = 860  → 810   (−50)
Deal 5   280 + 500 + 110 = 890  → 840   (−50)
Deal 6   500 + 420 + 110 = 1030 → 950   (−80)
Deal 1   220 × 2         = 440  → 400   (−40)
```

So: **Chicken Biryani (reg) = the 280 line**, **Singaporean Rice (reg) = the 500 line**, and
**Drink 500ml = the 110 soft drink** — i.e. the drink comes from the Tawakkal beverage list, which
means **beverages are sold at the Kashif Foods branch too** even though the red card does not
print them. See Q1.

⚠ The deals image is cut off below Deal 6. **If Deals 7+ exist, please send that part.**
⚠ Deal 4 says "Chicken Mayo Roll"; the card's item is "Chicken Mayo Garlic Roll". Assuming the same
item unless told otherwise.

---

## 5. What "same as Kashif Food" actually means

This is the part that went wrong last time, so it is spelled out.

Kashif Food's onboarding command seeded three operator roles with a broad permission set. The owner
then **cut them back by hand on 31 August** — 53 sidebar permissions + `tenant.dashboard.details`
removed from each role. **The live roles are much smaller than the seeder's originals**, and the
live state is the reference:

| Role | Live permissions today | Notes |
|---|---|---|
| Owner | 648 | everything |
| Delivery | **78** | the only role holding `tenant.reports.sales.channels` + `.riders` |
| Dine In | **76** | Delivery minus those two reports |
| Dine In (Restricted) | **62** | Dine In minus shift open/close, minus Review&Pay, minus returns |

What the live operator roles hold (families):

```
tenant.dashboard 1     tenant.pos 7          tenant.held-sales 4
tenant.sales-orders 8  tenant.sales-returns 4 tenant.sales-ledger 1
tenant.restaurant 17   tenant.shifts 8        tenant.printing 11
tenant.api 12          tenant.ajax 3          tenant.reports 2
```

What they **no longer** hold — and must not be granted on the new tenant either:
Report Center (index / print / export / sections / send-to-network), Rider Deliveries,
Customers, Products, Categories, Combos, Delivery Channels, Payment Methods, Units,
Modifier Groups, `tenant.dashboard.details`, and anything ending `.destroy` / `.delete`.

> **Build rule:** the new tenant's roles are cloned from **tenant 348's live
> `role_has_permissions` rows**, not re-derived from an allow/deny prefix list. A prefix list is
> what drifted last time. The seeder will read the live set, subtract permissions that do not
> exist on the new tenant, and insert exactly that.
>
> **Additive only** — `givePermissionTo`, never `syncPermissions`, and never insert a whole
> permission *family* in the name of a restore. On 31 August the families held 65 rows while each
> role held 53; the 12 difference were all `.destroy`, which onboarding never grants.

### Users

| # | User | Role | Branch | Order types |
|---|---|---|---|---|
| 1 | Owner | Owner | both | all |
| 2 | Kashif Foods counter | *(see Q6)* | The Kashif Foods | *(see Q5)* |
| 3 | Tawakkal counter | *(see Q6)* | Tawakkal Biryani | *(see Q5)* |

Each operator gets **its own named role** cloned from the same base — never a shared role, and
never another user type's role. Each gets a `manager_pins` row.

> ⚠ On Kashif Food all six users share one manager PIN, so every approval in the audit reads
> "Delivery Desk". **Give these two operators different PINs from the start**, and only give a PIN
> to whoever is actually allowed to approve.

---

## 6. Tenant shape

| | Value |
|---|---|
| Tenant code | `tawakkalkashif` *(Q7 — this becomes the subdomain)* |
| Domain | `tawakkalkashif.bingoopos.com` |
| Plan code | `tawakkal_restaurant` (its own plan, like `kashif_restaurant`) |
| Branch limit | 2 |
| Terminal limit | *(pending — owner will send terminals + printers)* |
| User limit | 5 (3 used, headroom) |
| Currency | PKR |

**Plan modules — POS + Finance, no inventory:**

| Enabled | Disabled |
|---|---|
| `pos`, `catalog`, `restaurant`, `printing`, `reports`, `sales_controls`, `multi_branch`, `users_roles`, `finance` | `inventory`, `stock_count`, `purchasing`, `kitchen_display`, `kitchen_inventory`, `manufacturing`, `offline_edge`, `erp_extensions`, `catering` |

`catalog` stays on — it owns products and categories, which POS cannot work without. `restaurant`
depends on Q5 (does either branch seat guests?).

Every product: `is_stock_tracked = 0`, consumption `none`, service-based — the same shape Kashif
Food already runs, so nothing in the POS asks about stock and no GL stock accounts are touched.

---

## 7. Open questions — please answer before I build

1. **Beverages at Kashif Foods.** The deals need a 110 drink, so the branch clearly sells drinks
   even though the red card omits them. Should the whole 8-item beverage list be available at
   Kashif Foods too, or only the 500 ml bottle used by the deals?
2. **Cherry Crunch prices.** Same at both branches? (Assumed yes.)
3. **Singaporean Rice Khas (2 / 4 Persons).** On the existing Kashif Food tenant, "Khass" is a
   *platter* whose BBQ parts print at the BBQ station. Here the card gives no contents. Is it
   (a) one plain dish, or (b) a platter with BBQ components that must reach the BBQ printer?
   This changes whether it is a product or a combo.
4. **Classic Platter (2300).** Same question — it lists six BBQ items plus rice, so it should be a
   combo for per-station KOT. But "Shashlik stick" is not on the BBQ menu and the rice is unnamed.
   Please name those two items (and whether they are sold standalone).
5. **Order types per branch.** Dine-in, takeaway, delivery, quick sale — which apply to each
   branch? Kashif Food's operators are split by exactly this, and it also decides whether tables
   and waiters are needed at all.
6. **Operator role type.** Should the two counter users be modelled on Kashif Food's **Dine In**
   (76), **Delivery** (78), or **Dine In (Restricted)** (62 — no shift open/close, no Review&Pay,
   no returns)?
7. **Tenant code / subdomain.** `tawakkalkashif` — or something shorter the client prefers? This is
   the client-facing URL, so it should be their call.
8. **Deals 7+** — is anything below Deal 6 on that page?
9. **The §2 decision** — A, B, or C.

---

## 8. What happens after the answers

1. Owner confirms §2 and §7.
2. If **C**: additive migration `categories.branch_id` (nullable) + POS grid filter + guard test,
   proven on the existing single-branch tenants first (they must behave identically).
3. `OnboardTawakkalKashifCommand` — idempotent, touching no other tenant, in the proven shape:
   master plan → tenant + domain + active subscription → provision → branches → catalogue →
   combos → roles cloned from live #348 → users.
4. **Then** terminals and printers (the owner will send these), and the per-category KOT routing
   that goes with them.
5. Full suite green, then deploy, then post-deploy verification on the live tenant — and a proof
   that Khatri Biryani and Kashif Food order counts and totals are untouched.
