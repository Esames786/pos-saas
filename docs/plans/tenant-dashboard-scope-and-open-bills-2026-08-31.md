# DASHBOARD-SCOPE-1 — dashboard sirf admin ke liye, aur us par khuli bills bhi dikhen

**Status:** DESIGN / PLAN — banaya nahi gaya
**Date:** 2026-08-31
**Author:** Claude
**For:** Kashif Food (LIVE). Har tenant par lagta hai.

---

## 1. Owner ki do demandein

> **(a)** "Dashboard me jo data aa raha hai wo [non-admin ko] kuch bhi nahi aayega."
>
> **(b)** "Admin ke liye main dashboard par extended data — sales, orders, discounts, cash — sab.
> Held wale neeche alag se bhi show hon. Abhi paid wale aate hain, lekin chhota sa neeche expected
> bhi aa raha ho jo held ya draft me atke hon."

Yani do alag kaam:
- **Scope** — counter operators ko dashboard ke aankday bilkul na dikhen
- **Content** — admin ko dikhne wale aankdon me *khuli bills* bhi shamil hon, alag se

---

## 2. Aaj kya hai

`/dashboard` par ye tiles hain: Net Sales Today · Orders Today · Avg Order Value · Cash Today ·
Card/Bank Today · Discounts Today · Tax Collected · Open Shifts · Expiry Alerts, phir Top 5 Products
aur Last 7 Days.

**Ye sab `status IN (paid, partially_returned)` par bante hain.** Jo bill abhi `held` ya `draft` hai —
yani mez par chal raha hai — wo kisi tile me nahi aata. 30 Aug ko **16 held orders, Rs 54,075** khule
thay; dashboard par un ka koi nishaan nahi tha.

`tenant.dashboard` **har role ko** milta hai — `2026_08_10_000004` migration ne usay baseline banaya
tha kyunki role bina us ke apne landing page par 403 kha raha tha.

---

## 3. (a) Scope — non-admin ko data na dikhe

### Masla

`tenant.dashboard` baseline hai, is liye har operator dashboard khol sakta hai **aur poore branch ke
aankday dekh sakta hai** — apni sales hi nahi, sab ki. Counter operator ko din ka total, discount aur
cash position janne ki zaroorat nahi.

### Do raaste

| | Kya | Asar |
|---|---|---|
| **A** | Nayi permission `tenant.dashboard.metrics` — sirf Owner ko. Us ke baghair dashboard khulta hai magar **tiles/charts render hi nahi hote**, sirf "Welcome" + shortcuts | Landing page kaam karta rehta hai (403 nahi), aankday chhup jate hain. **Meri sifarish.** |
| **B** | `tenant.dashboard` hi non-admin se hata dein | Landing page 403 — wahi bug jo `2026_08_10_000004` ne theek kiya tha. **Sifarish nahi.** |
| **C** | Aankday operator ke apne terminal tak mehdood | Zyada kaam, aur owner ne ye maanga hi nahi |

**A me karna kya hoga:**
- Nayi synthetic permission `tenant.dashboard.metrics`, migration me **sirf un roles ko** jinke paas
  `tenant.reports.center.index` hai (yani Owner/Manager) — taake kisi aur tenant ka behaviour na badle
- `DashboardController` metrics tab hi compute kare jab permission ho (khali query bhi na chale)
- Blade me `@can` — aur us ke baghair ek saaf "Welcome" screen

⚠️ **NEW ROUTE = CHECK NON-OWNER ROLES:** deploy.sh nayi permission sirf Owner ko deta hai. Kashif me
Manager jaisa koi role nahi, magar Khatri me hai — deploy ke baad wahan **additive** `givePermissionTo`
+ `system:clear-tenant-permission-cache` chalana hoga.

---

## 4. (b) Content — khuli bills alag se dikhen

### Kya add hoga

Maujooda tiles (paid) **waise ke waise** rahenge — un ka matlab "aaj kitna paisa aaya" hai aur usay
badalna reports ko jhoota kar dega. Uske **neeche ek alag qatar**:

```
┌─ Abhi khuli bills ────────────────────────────────────────────┐
│  Open Bills        16        Held + draft, abhi tak pay nahi   │
│  Expected          54,075    Agar sab abhi pay ho jayen        │
│  Oldest            3h 12m    Sab se purani khuli bill          │
│  Open Tables        9        Dine-in sessions abhi khuli       │
└───────────────────────────────────────────────────────────────┘
```

Aur Last 7 Days table me **"Open" column** — us din ki kitni bills abhi tak khuli hain.

### Query

```php
$open = SalesOrder::whereIn('status', ['held', 'draft'])
    ->whereRaw("coalesce(business_date, date(sale_date)) = ?", [$businessDate])
    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
    ->selectRaw('count(*) n, coalesce(sum(grand_total),0) amt, min(created_at) oldest')
    ->first();
```

⚠️ **Held bill ka `grand_total` "expected" hai, "earned" nahi.** Wo abhi badal sakta hai — round add
hoga, item cancel hoga, discount lagega. Is liye tile ka label **Expected** hi rahe, aur ye number
**kabhi bhi** Net Sales me na jode jaye.

⚠️ **Business date par filter** — `created_at` par nahi. Raat ki khuli bill agle din ki nahi ban jani
chahiye (`[[project_shift_timezone_track]]` ka wahi usool).

---

## 5. Kya NAHI karna

- **Net Sales me held ka paisa na milaayein.** Wo abhi aaya hi nahi. Owner ne bhi "neeche alag se"
  kaha hai — ek hi number me nahi.
- **Draft aur held ko alag na ginein** tiles me — dono "abhi khuli" hain. Chahen to tooltip me tafseel.
- **Cancelled ko na ginein** — wo khuli nahi, khatam ho chuki hai.

## 6. Test plan

- `DashboardScopeMySqlTest` — permission ke baghair metrics **compute hi na hon** (blade me `@can` par
  bharosa na karein; controller khali lautaye); permission ke saath poore aayen
- `DashboardOpenBillsMySqlTest` — 2 held + 1 draft + 1 paid + 1 cancelled → Open Bills = **3**,
  Expected = un teenon ka jama, paid wala tile **na badle**, cancelled kahin na aaye
- business date ka case: aadhi raat se pehle khuli bill agle din ke dashboard par **na aaye**
- Regression: Dashboard, Report Center, Scope suites
- `DashboardMatchesReportCentreRegressionTest` pehle se maujood hai — usay green rehna hai

## 7. Deploy notes

- Ek tenant migration (nayi permission + back-grant), `DashboardController`, dashboard blade.
- **NEW ROUTE = CHECK NON-OWNER ROLES** — upar dekhein.
- Khatri bhi isi code par — deploy se pehle uska Σ total unchanged prove karna hai.
