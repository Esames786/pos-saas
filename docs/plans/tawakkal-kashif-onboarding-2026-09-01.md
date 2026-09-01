# Tawakkal + The Kashif Foods — onboarding specification (FINAL)

**Status:** SPEC AGREED — nothing seeded, nothing provisioned, no code written yet.
**Date:** 2026-09-01 · Built from the owner's six menu images and their decisions.
**Reference tenant for roles / screens / data scope:** **Kashif Food (#348), as it is LIVE today** —
not what its onboarding command originally wrote. (Kashif Food was built off Khatri Biryani and that
produced role mistakes the owner had to correct by hand on 31 August. The live rows are the truth.)

---

## 1. Decisions locked by the owner

| | |
|---|---|
| One tenant, **two branches** | The Kashif Foods · Tawakkal Biryani |
| **Two URLs** | `thekashiffoods.bingoopos.com` · `tawakkalbiryani.bingoopos.com` |
| Modules | **POS + Finance only.** No `inventory`, no `kitchen_inventory`, no `purchasing`, no `stock_count` — the whole stock side stays off (owner-confirmed twice) |
| Accounts | **3 total** — 1 Owner, 1 Tawakkal user, 1 Kashif Foods user |
| Terminals | **2** — one per branch, one user bound to each |
| Order types | **Quick Sale + Takeaway only. No dine-in. No delivery.** |
| Menu separation | **Categories carry `branch_id`** — each counter sees only its own menu |
| Permissions | Cloned from Kashif Food's **live** operator role, row for row |

---

## 2. Two URLs, one tenant — how this works

Natively supported, **no code change**. `tenant_domains.domain` is unique but `tenant_id` is an
ordinary foreign key, so a tenant may own any number of domains; `IdentifyTenant` looks the host up
and activates that tenant.

```
tenant_domains
  thekashiffoods.bingoopos.com    → tenant tawakkalkashif   is_primary = 1
  tawakkalbiryani.bingoopos.com   → tenant tawakkalkashif   is_primary = 0
```

**What the URL does and does not do.** Both addresses reach the same application and the same login
page. The **branch is decided by the logged-in user**, not by the address — each operator has a
`default_branch_id`, so the Tawakkal user lands on Tawakkal from either URL. In practice each side
uses its own address for branding and the branch follows the login, which is the behaviour we want.

> If the owner later wants the address itself to *pin* the branch (so the wrong login cannot even
> reach the other branch), that is a separate, small change — say the word and it gets its own plan.

⚠️ **Certificate.** Both subdomains ride the wildcard `*.bingoopos.com`, which **expires
2026-09-17** and whose renewal is a manual DNS-01 configuration that `certbot renew` cannot run
unattended. Two new client-facing URLs make this more urgent, not less. It needs handling before
mid-September regardless of this tenant.

---

## 3. The one code change: categories become branch-aware

**Why it is needed.** A tenant has one product book, and the POS grid is not branch-aware. Without
this, the Tawakkal cashier's screen shows all 19 BBQ items and all 6 rolls, and the Kashif Foods
cashier sees Chana Pulao and Beef Pulao. Both counters would get a menu that is not theirs.

**What changes.**

```
migration (additive):   categories.branch_id  nullable, indexed, FK → branches
POS grid filter:        WHERE branch_id IS NULL OR branch_id = :selected_branch
```

`NULL` means "every branch" — which is what every existing category in every existing tenant will
be, so their behaviour is unchanged by construction.

**Why the blast radius is small — verified in the code, not assumed:**

| Consumer | How it uses a category | Effect |
|---|---|---|
| KOT routing (`CategoryPrinterMapping`) | product's category → printer | **none** — and that table is *already* branch-scoped: `unique(branch_id, category_id, print_role)` |
| Sales reports | `groupBy('p.category_id')` | none |
| Departments | `departments` already branch-scoped (`unique(branch_id, code)`) | none |
| Catering | reads `CategoryPrinterMapping` | none |
| Manufacturing | does not reference categories at all | none |
| Edge local POS | writes `category_id` on a line only | none |

**The filter is applied in the POS grid only.** Every admin and configuration screen — Category CRUD,
Product form, Combo form, printer mapping, report filters, bulk import — keeps showing all
categories, because the owner configures both branches from one place.

**Two things to get right during the build:**

1. `EdgeBootstrapService` exports categories with an **explicit column list**
   (`id, parent_id, code, name, slug, sort_order, is_active`). `branch_id` must be added there or the
   Edge box would see everything while the cloud filters. The Edge importer already understands that
   a `NULL branch_id` row is global. (Edge is dormant on production; the code must still stay
   coherent.)
2. `categories.slug`, `categories.code` and `products.sku` are **globally unique within a tenant**.
   Both branches may show a category named "Chicken Biryani", but the slugs must differ
   (`chicken-biryani-kf` / `chicken-biryani-tb`). This is not new — it is how the table has always
   been.

**Live-tenant safety.** All three paying tenants are single-branch, so the filter returns exactly
what it returns today for them:

```
khatribiryani  1 branch    kashifkitchen  1 branch    kashiffood  1 branch
(multi-branch exists only in demo tenants: demo 3, enterprisedemo 4, restaurantprodemo 2,
 inventorydemo 2, financedemo 2 — where a mistake is cheap and visible)
```

The guard test must prove a single-branch tenant's POS payload is **byte-identical** before and
after.

---

## 4. Tenant, plan and access

### 4.1 Master

| | |
|---|---|
| Tenant code | `tawakkalkashif` |
| Business name | Tawakkal & The Kashif Foods |
| Plan code | `tawakkal_restaurant` (its own custom plan) |
| Currency | PKR |
| Branch limit | 2 · Terminal limit 2 · User limit 5 (3 used) |
| Subscription | active, 1 year |

**Modules**

| Enabled | Disabled |
|---|---|
| `pos`, `catalog`, `restaurant`\*, `printing`, `reports`, `sales_controls`, `multi_branch`, `users_roles`, `finance` | `inventory`, `stock_count`, `purchasing`, `kitchen_display`, `kitchen_inventory`, `manufacturing`, `offline_edge`, `erp_extensions`, `catering` |

\* `restaurant` stays enabled only because the cloned operator role carries 17 `tenant.restaurant.*`
permissions (see §5.2). There will be no floors, tables or waiters.

**The entire stock side is off** — `inventory`, `kitchen_inventory`, `purchasing` and `stock_count`
are all disabled on the plan. `EnsureTenantSubscriptionAccess` refuses those routes at the
middleware, and every product is service-based on top of that, so there is no second place for stock
to leak in: no stock balances, no goods receipts, no recipes, no consumption postings, and no stock
GL accounts touched. Finance stays fully on — chart of accounts, cash & bank, expenses, sales
ledger and the daily money reports all work as they do on Kashif Food.

### 4.2 Branches, terminals, users

| Branch | Terminal | User | Role | Order types | Default |
|---|---|---|---|---|---|
| The Kashif Foods | `T1` — Kashif Foods Counter | `counter_kf@bingoopos.com` | Counter — Kashif Foods | quick_sale, takeaway | takeaway |
| Tawakkal Biryani | `T2` — Tawakkal Counter | `counter_tb@bingoopos.com` | Counter — Tawakkal | quick_sale, takeaway | takeaway |
| both | — | `owner_tk@bingoopos.com` | Owner | all | — |

Each operator is bound to **its own terminal only** (`terminal_user`) and **its own branch only**
(`branch_user`). Each gets its own named role — never a shared role, never another user type's role.

**Manager PINs: two different PINs.** On Kashif Food all six users share one PIN, so every approval
in the audit reads "Delivery Desk" and the trail is meaningless. That mistake is not repeated here.

---

## 5. Permission matrix — cloned from Kashif Food, live

### 5.1 What the reference actually holds today

| Role on #348 | Permissions | |
|---|---|---|
| Owner | 648 | everything |
| Delivery | 78 | Dine In **+** `reports.sales.channels`, `reports.sales.riders` |
| **Dine In** | **76** | ← **the base for both operators here** |
| Dine In (Restricted) | 62 | Dine In **−** shift open/close, `pos.store`, returns, `pos.change-terminal` |

**Why Dine In (76) and not the other two.** Delivery's only extra is two delivery-report screens,
useless where there is no delivery. Restricted removes shift open/close, Review & Pay and returns —
but with only one operator per branch, each *must* be able to open and close their own shift and
take payment. Dine In is the plain counter role.

### 5.2 The exact 76 permissions to clone

```
tenant.dashboard

tenant.pos.index                       tenant.pos.store
tenant.pos.change-terminal             tenant.pos.void-kot-item
tenant.pos.printing.retry              tenant.pos.customers.quick-store
tenant.pos.customers.addresses.store

tenant.held-sales.index                tenant.held-sales.create
tenant.held-sales.store                tenant.held-sales.cancel

tenant.sales-orders.index              tenant.sales-orders.create
tenant.sales-orders.store              tenant.sales-orders.show
tenant.sales-orders.cancel             tenant.sales-orders.rider.update
tenant.sales-orders.split-bill         tenant.sales-orders.split-bill.store

tenant.sales-returns.index             tenant.sales-returns.create
tenant.sales-returns.store             tenant.sales-returns.show
tenant.sales-ledger.index

tenant.shifts.index                    tenant.shifts.create
tenant.shifts.store                    tenant.shifts.show
tenant.shifts.close-form               tenant.shifts.close
tenant.shifts.close-branch-form        tenant.shifts.close-branch

tenant.printing.documents.kot          tenant.printing.documents.receipt
tenant.printing.documents.reminder     tenant.printing.documents.preview
tenant.printing.jobs.index             tenant.printing.jobs.queue-kot
tenant.printing.jobs.queue-receipt     tenant.printing.jobs.mark-printed
tenant.printing.jobs.retry             tenant.printing.jobs.reprint-reminder
tenant.printing.jobs.confirm-reminders

tenant.api.pos.bill-preview            tenant.api.pos.held-sales
tenant.api.pos.recent-sales            tenant.api.pos.totals.quote
tenant.api.pos.shift-status            tenant.api.pos.print-jobs
tenant.api.pos.promotions.quote        tenant.api.pos.table-board
tenant.api.pos.table-sessions          tenant.api.pos.table-sessions.open-orders
tenant.api.catalog.barcode.lookup      tenant.api.manager-approvals.verify

tenant.ajax.products                   tenant.ajax.customers
tenant.ajax.sales

tenant.restaurant.board                tenant.restaurant.tables.index
tenant.restaurant.tables.store         tenant.restaurant.tables.update
tenant.restaurant.floors.index         tenant.restaurant.floors.store
tenant.restaurant.floors.update        tenant.restaurant.waiters.index
tenant.restaurant.waiters.store        tenant.restaurant.waiters.update
tenant.restaurant.table-sessions.open  tenant.restaurant.table-sessions.show
tenant.restaurant.table-sessions.close tenant.restaurant.table-sessions.move
tenant.restaurant.table-sessions.merge tenant.restaurant.table-sessions.bill-preview
tenant.restaurant.table-sessions.bill-requested
```

### 5.3 What is deliberately absent — and must stay absent

Report Center (index / print / export / sections / send-to-network), Rider Deliveries, Customers,
Products, Categories, Combos, Delivery Channels, Payment Methods, Units, Modifier Groups,
`tenant.dashboard.details`, Branches, Terminals, Users, Roles, Finance, Inventory, Purchasing,
Settings — and **anything ending `.destroy` or `.delete`**.

> **Two build rules, both learned the hard way.**
> 1. Clone from tenant #348's **live `role_has_permissions` rows**, never re-derive from an
>    allow/deny prefix list — the prefix list is exactly what drifted last time.
> 2. **Additive only** (`givePermissionTo`, never `syncPermissions`), and never insert a whole
>    permission *family*. On 31 August the families held 65 rows while each role held 53; the 12
>    difference were all `.destroy`, which onboarding never grants. Inserting the family in the name
>    of a "restore" would have granted 12 new permissions.

### 5.4 The one thing to decide

The 76 include **17 `tenant.restaurant.*`** permissions. They are inert — `allowed_order_types`
is `[quick_sale, takeaway]`, and `POSController` aborts 403 on any table session for a user not
allowed dine-in — but the **Restaurant menu will appear in the sidebar** with Tables / Floors /
Waiters screens for a business that seats nobody.

**Recommendation: clone all 76 verbatim** (exact parity with the reference, which is what was
asked), and if the owner then wants the Restaurant menu gone, remove those 17 in a **separate,
reversible step** — exactly how Kashif Food's own hides were done, so the change is visible and
undoable. One word from the owner either way.

---

## 6. The catalogue

69 products, 6 combos. Prices transcribed verbatim from the cards. Every product is
**service-based** — `is_stock_tracked = 0`, consumption `none` — because there is no inventory
module, so nothing touches stock balances or stock GL accounts.

### 6.1 The Kashif Foods — 45 items · SKU prefix `KF-`

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

| Item | Price | Item | Price |
|---|---|---|---|
| Chicken Tikka (Chest) | 450 | Chicken Malai Kabab | 550 |
| Chicken Tikka (Leg) | 420 | Beef Dhaga Kabab (Fry) | 550 |
| Chicken Malai Tikka (Chest) | 500 | Beef Dhaga Kabab | 500 |
| Chicken Malai Tikka (Leg) | 480 | Beef Seekh Kabab | 500 |
| Chicken Bihari Tikka (Chest) | 460 | Beef Bihari Boti | 550 |
| Chicken Bihari Tikka (Leg) | 430 | Chandan Kabab | 500 |
| Chicken Malai Boti | 550 | Paratha (Small) | 60 |
| Chicken Shahi Chatakh | 580 | Paratha (Large) | 120 |
| Chicken Boti Boneless | 500 | | |
| Chicken Dhaga Kabab | 500 | | |
| Chicken Reshmi Kabab | 500 | | |

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
Printed contents: Tikka chest, Shashlik stick, Malai boti, Shahi Chattekh, Reshmi kabab,
Seekh kabab and Rice.

### 6.2 Tawakkal Biryani — 20 items · SKU prefix `TB-`

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
| Soft Drink 300 ml | 80 |
| Soft Drink 500 ml | 110 |
| Soft Drink 1 Ltr | 150 |
| Soft Drink 1.5 Ltr | 180 |
| Soft Drink Jumbo | 240 |
| Mineral Water (Small) | 50 |
| Mineral Water (Large) | 100 |
| Raita | 50 |

*(Raita sits under Beverages on the card and is kept there.)*

### 6.3 Both branches — Cherry Crunch (4) · SKU prefix `CC-`

| Item | Price |
|---|---|
| Cherry Crunch (Cup) | 120 |
| Cherry Crunch (250 g) | 280 |
| Cherry Crunch (Half Pack) | 550 |
| Cherry Crunch (Full Pack) | 1100 |

Category seeded with `branch_id = NULL` → visible at both counters.

---

## 7. Deals — The Kashif Foods only (6 combos)

Combos carry `branch_id` natively, so these bind to branch 1 with no code change.

| Deal | Price | Components |
|---|---|---|
| Deal 1 | 400 | 2 × Chicken Chatni Roll |
| Deal 2 | 560 | Chicken Biryani + Chicken Chatni Roll + Soft Drink 500 ml |
| Deal 3 | 760 | Chicken Biryani + Chicken Tikka (Leg) + Soft Drink 500 ml |
| Deal 4 | 810 | Singaporean Rice + Chicken Mayo Garlic Roll + Soft Drink 500 ml |
| Deal 5 | 840 | Chicken Biryani + Singaporean Rice + Soft Drink 500 ml |
| Deal 6 | 950 | Singaporean Rice + Chicken Tikka (Leg) + Soft Drink 500 ml |

**The arithmetic confirms what "(reg)" and "Drink 500ml" mean** — no guessing was needed:

```
Deal 1   220 × 2          = 440   → 400    (−40)
Deal 2   280 + 220 + 110  = 610   → 560    (−50)
Deal 3   280 + 420 + 110  = 810   → 760    (−50)
Deal 4   500 + 250 + 110  = 860   → 810    (−50)
Deal 5   280 + 500 + 110  = 890   → 840    (−50)
Deal 6   500 + 420 + 110  = 1030  → 950    (−80)
```

So **Chicken Biryani (reg) = the 280 line**, **Singaporean Rice (reg) = the 500 line**, and
**Drink 500 ml = the 110 soft drink**. Which also settles a question the red card leaves open:
**beverages are sold at The Kashif Foods too**, so the beverage category is seeded with
`branch_id = NULL` (both branches), same as Cherry Crunch.

---

## 8. Onboarding completeness checklist

Built from an actual table-by-table inventory of the live Kashif Food tenant, so nothing is
carried by memory. Every row is either **provided automatically**, **seeded by the command**, or
**deliberately not created**.

### 8.1 Provided automatically by `TenantProvisioner` — the command must not duplicate these

| Table | What arrives |
|---|---|
| `languages` | en + ur |
| `currencies` | PKR, default |
| `accounts` | full chart of accounts (51) |
| `cash_bank_accounts` | default cash + bank |
| `expense_categories` | 9 defaults |
| `payment_methods` | Cash, Card (+ mapping to cash/bank accounts) |
| `void_reasons` | default set |
| `receipt_layout_settings` | KOT / Receipt / Reminder layouts |
| `branches` | one "Main Branch" (renamed by the command) |
| `users`, `roles`, `permissions` | Owner user, Owner role, all 648 route permissions |

### 8.2 The command must create

| # | Item | Detail |
|---|---|---|
| 1 | Master plan | `tawakkal_restaurant` + 9 modules on, 9 off + 4 features |
| 2 | Tenant + **2 domains** + subscription | see §2 |
| 3 | Provision | DB, migrations, base seed, Owner |
| 4 | Branch 1 renamed | "The Kashif Foods" |
| 5 | **Branch 2 created** | "Tawakkal Biryani" |
| 6 | Unit | `EA` (Each) |
| 7 | Terminals | `T1` (branch 1), `T2` (branch 2); every other terminal set inactive |
| 8 | Categories | 12 total — 7 branch 1, 3 branch 2, 2 shared (`branch_id = NULL`) |
| 9 | Products | 69, all service-based, SKU prefixed `KF-` / `TB-` / `CC-` |
| 10 | Combos + components | 6 deals bound to branch 1 |
| 11 | Printers | **pending — owner will send** |
| 12 | `category_printer_mappings` | **pending — depends on 11** |
| 13 | `terminal_printer_settings` | **pending — depends on 11** |
| 14 | Roles | 2 operator roles, cloned row-for-row from #348's live Dine In |
| 15 | Users | 2 operators + Owner; `branch_user`, `terminal_user`, `allowed_order_types` |
| 16 | `manager_pins` | 2 rows, **two different PINs** |

### 8.3 Deliberately NOT created

`restaurant_floors`, `restaurant_tables`, `restaurant_waiters` (no dine-in) ·
`delivery_channels`, `delivery_riders` (no delivery) · `suppliers`, stock balances, goods receipts
(no inventory) · `catering_service_time_presets` · `customers` (they arrive as trade happens) ·
`report_schedules` (offer the nightly owner e-mail once the tenant is trading).

---

## 9. Build order

1. **Category branch scoping** — additive migration + POS grid filter + `EdgeBootstrapService`
   column + guard test proving a single-branch tenant's POS payload is unchanged.
2. **`OnboardTawakkalKashifCommand`** — idempotent, `--yes` guarded, touching no other tenant:
   master plan → tenant + two domains + subscription → provision → branches → catalogue → combos →
   roles cloned from live #348 → users.
3. **Printers and routing** — once the owner sends the hardware.
4. **Full suite green**, then deploy, then post-deploy verification on the live tenant.
5. **Proof that Khatri Biryani and Kashif Food order counts and totals are untouched**, before and
   after, exactly as every previous deploy has been proven.

## 10. Still needed from the owner

1. **Printers** — how many, where, and which categories print at which station.
2. **Deals 7+** — the deals image is cut off below Deal 6; if more exist, that part of the card.
3. **Classic Platter (2300) and Singaporean Rice Khas (1550 / 2500)** — plain dishes, or platters
   whose BBQ parts must reach a BBQ printer? Only matters once there is more than one printer per
   branch; until then both are plain products. "Shashlik stick" is not otherwise on the card.
4. **The 17 restaurant permissions** — keep for exact parity (recommended) or drop the sidebar menu.
