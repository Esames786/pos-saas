# KASHIF-OPERATOR-HIDE — jo owner ne screenshots par cross kiya

**Tenant:** Kashif Food #348 (`kashiffood.bingoopos.com`) — LIVE
**Date:** 2026-08-31
**Rule:** Owner ke ilawa har role se hatana hai. Owner ka kuch nahi chhoona.
**Method:** `role_has_permissions` ki makhsoos rows DELETE karna — **kabhi `syncPermissions` nahi**
(wo baaqi sab permissions bhi uda deta hai). Har revoke ke baad
`system:clear-tenant-permission-cache`.

Roles jin par lagta hai: **#2 Delivery**, **#3 Dine In**, **#4 Dine In (Restricted)**.
Owner = role #1. Koi per-user (`model_has_permissions`) grant kahin nahi hai — check kiya, 0.

---

## Batch 1 — 30 Aug ki raat wali images ✅ HO CHUKA

Cross tha: **Sales Report Center**, **Rider Deliveries**, aur POS ka **Report** button.

| Kya | Permission | Kyun |
|---|---|---|
| Sales Report Center | `tenant.reports.center.index` | menu entry |
| POS ka Report button | *wahi* `tenant.reports.center.index` | button isi permission par gated hai — ek revoke se dono gaye |
| Center ke andar ke amal | `.print` `.export` `.send-to-network` `.sections.{cancellations,categories,items,order-type-combos,order-types,waiters}` | warna URL type kar ke screen khul jaati |
| Rider Deliveries | `tenant.reports.sales.riders` | menu entry |

**Nateeja:** 33 rows delete (11 permission × 3 roles). Owner ke paas 11/11 salamat.
Har user par asli `can()` se tasdeeq: owner haan, paanchon operators nahi.
Transactions unchanged (#348 = 379 orders / Rs 817,347).

---

## Batch 2 — 31 Aug ki dashboard images

Chaar screenshots. Har ek me laal cross:

### Image 1 — sidebar, Catalog khula
Cross: **Products**, **Modifiers**, **Categories**.
Units of Measure cross ke bilkul neeche tha — **owner se pucha, jawab: "ye bhi hide kardein"**.

### Image 2 — dashboard ka neeche wala hissa
Do bade cross: **Top 5 Products Today** table par, aur **Last 7 Days — Net Sales** table par.
Upar wale tiles (Net Sales / Orders / Avg Order / Cash / Card-Bank / Discounts / Tax /
Open Shifts / Expiry) par **koi cross nahi tha**.

> 30 Aug ki raat owner ne kaha tha "dashboard me jo data aa raha hai wo kuch bhi nahi aayega".
> 31 Aug ki image us se kam maang rahi thi. **Pucha, faisla: sirf dono tables chhupani hain,
> tiles operator ko dikhte rahenge.**

### Image 3 — sidebar, Sales Controls khula
Cross: **Combos**.

### Image 4 — sidebar, Sales khula
Cross: **Customers**, **Payment Methods**, **Delivery Channels**, **Delivery Riders**.
**POS, Sales Orders, Sales Returns, Sales Ledger par cross NAHI tha** — wo rahenge.

---

## Batch 2 ka amal

### (a) Sidebar — sirf data, koi deploy nahi

Poora khandan hatana hai, sirf `.index` nahi — warna menu to chhup jaayega magar
banda URL type kar ke andar chala jayega.

| Menu | Permission prefixes | Rows |
|---|---|---|
| Products | `tenant.products.` + `product-variants.` + `product-barcodes.` + `product-branch-prices.` | 42 |
| Modifiers | `tenant.modifier-groups.` | 15 |
| Categories | `tenant.categories.` | 15 |
| Units of Measure | `tenant.units.` + `unit-conversions.` | 24 |
| Combos | `tenant.combos.` | 15 |
| Customers | `tenant.customers.` | 21 |
| Payment Methods | `tenant.payment-methods.` | 9 |
| Delivery Channels | `tenant.delivery-channels.` | 9 |
| Delivery Riders | `tenant.delivery-riders.` | 9 |
| | **TOTAL** | **159** |

⚠️ **POS in me se KUCH BHI istemal nahi karta** — ye check kiya gaya hai. POS page sirf ye
URLs maangta hai: `/ajax/customers`, `/pos/customers/quick-store`, `/api/pos/*`,
`/held-sales`, `/sales-orders`, `/restaurant/*`, `/printing/*`, `/shifts/open`.
Riders, payment methods, delivery channels aur combos POSController khud payload me daalta
hai — un ke liye koi permission darkar nahi. Is liye delivery wale ka customer banana,
rider lagana, combo bechna — sab chalta rahega.

**Hatane ke baad lazmi sabit karna:** har operator ke paas ye salamat hain —
`tenant.pos.index`, `tenant.pos.store`, `tenant.ajax.products`, `tenant.ajax.customers`,
`tenant.ajax.sales`, `tenant.pos.customers.quick-store`, `tenant.held-sales.store`,
`tenant.sales-orders.index`, `tenant.sales-returns.create`,
`tenant.restaurant.table-sessions.open` — aur har user ke liye POS screen 200 par render ho.

> `floor4_kf` ke paas `tenant.shifts.index` aur `tenant.pos.store` pehle se nahi hain.
> Ye **jaan boojh kar** hai (owner ne 30 Aug ko kaha tha) — is kaam ka side effect nahi.

### (b) Dashboard ki do tables — ye CODE hai, deploy chahiye

Aaj tak dashboard ki koi cheez permission-gated nahi. `tenant.dashboard` har role ko milta hai
(migration `2026_08_10_000004` ne baseline banaya tha, warna role apne landing page par 403
khata tha) — is liye ek **nayi permission** banani hogi.

Tajweez: `tenant.dashboard.details` — sirf Owner ko.

- `DashboardController` `topProducts` aur `last7Days` **compute hi na kare** jab permission na ho
  (blade ke `@can` par bharosa na karein — controller khali lautaye, warna query phir bhi chalti hai)
- blade me dono card `@can('tenant.dashboard.details')` ke peeche
- tiles ko haath nahi lagana — un par cross nahi tha

⚠️ **NEW ROUTE = CHECK NON-OWNER ROLES:** `deploy.sh` nayi permission sirf Owner ko deta hai.
Khatri #212 par bhi yehi code jayega — wahan Owner ke ilawa Manager role hai; deploy ke baad
tay karna hoga ke usay milni chahiye ya nahi, aur **additive** `givePermissionTo` se deni hogi.

**Test:** `DashboardDetailsScopeMySqlTest` — permission ke baghair dono cards HTML me maujood
na hon **aur** controller ne query chalayi hi na ho; permission ke saath dono aayen. Deploy se
pehle dono tenants ka dashboard khud render kar ke dekhna (aaj ke do outage ka sabaq —
[[feedback_test_must_run_the_real_path]]).

---

## Jo cross NAHI tha (yaad rahe)

Sidebar me ye operator ke paas rahenge: **POS, Sales Orders, Sales Returns, Sales Ledger,
Restaurant, Kitchen Inventory, Printing, Operations**, aur dashboard ke **saare upar wale tiles**.
Inventory / Purchasing / Finance in roles ke paas pehle se nahi thay.
