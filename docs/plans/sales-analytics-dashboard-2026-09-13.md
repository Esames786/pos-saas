# SALES-ANALYTICS-1 — graphs wala sales aur growth ka safha (Owner-only)

**Tareekh:** 2026-09-13
**Haalat:** tehqeeq mukammal · **code nahi likha, kuch deploy nahi hua**
**Owner ki maang:** *"dashboard — analytical graph report with multiple graphs which show sales and
growth, filter for last 6 month or date range wise, new permission, for now only admin account can see"*

---

## 1. Khush-khabri — bunyad pehle se mojood hai

Tehqeeq ka sab se ahem nateeja ye ke **is kaam ke liye koi nayi cheez nahi kharidni/laani parregi.**

| Cheez | Haalat |
|---|---|
| **ApexCharts** | `public/assets/plugins/apexchart/apexcharts.min.js` — theme ke sath **pehle se disk par**. Aaj **kahin load nahi hoti** aur project me **ek bhi chart nahi** hai. |
| **Date range picker** | `daterangepicker.js` + `moment.js` — layout me **pehle se load** hain |
| **Din-ba-din data** | `SalesReportService::dailyStats($from, $to, $branchId, $scopeUser)` — **pehle se mojood** |
| **Index** | `sales_orders (branch_id, business_date)` — bilkul wohi jo time-series query maangti hai |

⚠️ **CDN ki zaroorat nahi.** Ye ahem hai: POS branch ke local network par chalta hai aur internet
band ho sakta hai. Library disk se aayegi, kisi bahar ki cheez par daromadar nahi.

### `dailyStats()` kyun ahem hai — ise dobara nahi likhna

Ye method `DASHBOARD-7DAY-POPULATION-1` me ek **asli bug ke ilaj** ke taur par bana tha, aur uska
docblock wo kahani darj karta hai: dashboard ka "Last 7 Days" card pehle apni alag query chalata tha
(`status = paid` sirf, returns ghataye baghair), is liye 1 Sep ko upar tile "Orders Today 295" aur
neeche wali row "291" dikha rahi thi — aur do din ka 1,400 + 2,490 ka asli revenue bhi ghayab tha.

Ab wo:

```php
population = SalesReportEngine::POPULATION   // paid + partially_returned + returned
net_sales  = SUM(grand_total) − us business day ke posted returns
day        = COALESCE(business_date, DATE(sale_date))     // businessDayExpr()
scope      = UserDataScope::applyToSales(...)
```

Yani wohi ginti jo **baqi saare reports** karte hain. **Naya aggregation likhna sab se bara khatra
hota** — do jagah do hisaab, aur owner ko dashboard par kuch aur, report par kuch aur nazar aata.
**Is safhe ka har number `dailyStats()` se aayega.**

---

## 2. ⚠️ Data ki haqeeqat — "6 mahine" abhi mumkin nahi

Prod par (13 Sep):

| Tenant | Paid orders | **Din ka data** | Daur |
|---|---|---|---|
| khatribiryani | 9,376 | **34** | 11 Aug – 13 Sep |
| kashiffood | 5,575 | **15** | 30 Aug – 13 Sep |
| tawakalkashif | 1,918 | **8** | 6 Sep – 13 Sep |
| kashifkitchen | 0 | 0 | — |

**Kisi ke paas 6 mahine ka data hai hi nahi.** Sab se purana Khatri, sirf 34 din.

Iska matlab ye **nahi** ke feature na banayen — balki ye ke:

- "Last 6 months" ka button banega, magar abhi **zyadatar khali mahine** dikhayega
- Safhe ko **khali daur ka sharif jawab** dena hoga ("is daur me koi sale nahi"), khali graph nahi
- **Growth ka moqabala** (is daur vs pichla daur) tab tak be-maani rahega jab tak pichla daur mojood
  na ho — us soorat me "moqabale ke liye data nahi" likhna hoga, `0%` ya `∞%` nahi

Shuru me sab se kaam ka filter **"last 30 days"** hoga, 6 mahine nahi. Ye owner ko pehle se pata hona
chahiye warna wo safha khula kar samjhega ke kuch toota hua hai.

---

## 3. Raftaar — masla nahi

Khatri ke poore daur par din-ba-din query:

```
34 din, 23.4 ms
```

Aur `business_date` **kisi bhi tenant par kabhi NULL nahi** (khatri 9,530 / kashiffood 5,697 /
tawakal 1,934 rows — sab bhare hue), is liye `COALESCE` sirf ehtiyat hai aur index kaam karta hai.
6 mahine (~180 din, ~50,000 orders) bhi 200ms se neeche rahega.

---

## 4. Safha kahan bane — **dashboard par NAHI, alag safha**

Owner ne "dashboard" kaha, magar mera mashwara **alag safha** hai jis ka link dashboard par ho:

| | Dashboard par daalna | Alag safha |
|---|---|---|
| Har cashier ke har login par 6 queries chalengi | ❌ haan | ✅ nahi |
| Permission se gate karna | mushkil (dashboard sab ka hai) | ✅ saaf |
| Date range picker dashboard ke tiles se takrayega | ❌ haan | ✅ nahi |

Dashboard **har cashier** har shift me kholta hai. Us par 6 charts ka bojh daalna POS ki raftaar par
asar dalega — bila zaroorat, kyunke ye safha sirf Owner ke liye hai.

**Tajweez:** `/reports/analytics` (Reports ke menu me), aur dashboard par ek chhota "Analytics" ka
button jo Owner ko hi nazar aaye.

---

## 5. Kaunse graphs

Sab `dailyStats()` + mojooda `SalesReportEngine` se — koi naya hisaab nahi:

| # | Graph | Data kahan se | Nayi mehnat |
|---|---|---|---|
| 1 | **Sales ka rujhan** (line/area) — net sales per din | `dailyStats()` | — |
| 2 | **Growth** — is daur vs pichla barabar daur | `dailyStats()` do bar | PHP me hisaab |
| 3 | **Orders ki ginti** (bar) | `dailyStats()` | — |
| 4 | **Average order value** (line) | `dailyStats()` (net ÷ orders) | — |
| 5 | **Category ke hisab se** (bar) | `SalesReportEngine::byCategory()` | — |
| 6 | **Order type** (donut) — Dine In / Takeaway / Delivery / Quick Sale | `byOrderType()` | — |
| 7 | **Payment method** (donut) | `overview()['payments']` | — |

⚠️ **Do alag paimane (scales) ek graph par kabhi nahi** — jaise "sales" aur "orders" ek hi chart par
do y-axis ke sath. Wo sab se aam chart ki ghalti hai aur padhne wale ko dhoka deti hai. Ya to alag
chart, ya dono ko ek buniyad par index karo.

**Jo abhi NAHI** (alag kaam, agar owner kahe): ghanton ke hisab se (peak hours) — is ke liye nayi
query chahiye, `dailyStats()` sirf din deta hai.

---

## 6. Permission — "sirf admin"

**"Admin" is system me `Owner` role hai.** Prod par har tenant ke roles:

```
khatribiryani   cash, Delivery, Dine In, Manager, Owner, Quick Sale, Takeaway
kashiffood      Delivery, Dine In, Dine In (Restricted), Owner
kashifkitchen   Owner
tawakalkashif   Kashif Foods Counter, Owner, Tawakkal Counter
```

Koi alag "Admin" role hai hi nahi — har tenant me theek ek `Owner`.

**Aur yahan wo jaal hamare HAQ me hai.** `EnsureRoutePermission` route ke NAAM par gate karta hai, aur
`deploy.sh` naye route ki permission **sirf Owner ko** deta hai. Doosre roles ko kuch nahi milta jab
tak koi alag se additive `givePermissionTo` na chalaye.

> **Yani: naya route banao, deploy karo, aur additive grant CHALAO HI MAT.**
> Nateeja khud ba khud Owner-only.

(Yehi jaal pichle kaam me hamare khilaf tha — wahan cashier ko chahiye tha aur alag se dena para.
Yahan ulta hai.)

⚠️ Khatri ke paas **Manager** role bhi hai. Owner baad me kahe ke Manager bhi dekhe, to wo ek satar
ka additive grant hai — magar **abhi nahi**.

---

## 7. Khatre

| # | Khatra | Tadbeer |
|---|---|---|
| 1 | **Dashboard se numbers na milna** — safha kuch aur dikhaye, Report Center kuch aur | Har number `dailyStats()`/`SalesReportEngine` se. **Koi nayi query nahi.** Guard: usi daur par safhe ka jama = `overview()['net_sales']` |
| 2 | **Khali daur** — abhi 6 mahine ka data hai hi nahi | Har graph ka "koi sale nahi" wala sharif jawab; growth par "moqabale ka data nahi", `0%`/`∞%` nahi |
| 3 | **Growth ka batta sifar** (pichle daur me 0 sale) | Division-by-zero ka saaf jawab — `∞%` ya `NaN` kabhi nazar na aaye |
| 4 | Dashboard ki raftaar | Alag safha (§4) — dashboard chhua hi nahi jata |
| 5 | Multi-branch (tawakal ke 2 branch) | Branch filter lazmi; `UserDataScope` pehle se `dailyStats()` me lagta hai |
| 6 | **Naya route = naya permission** | Is bar ye faida hai (§6) — bas additive grant mat chalao |
| 7 | ApexCharts pehli bar load hogi | Sirf **isi safhe** par `@push('scripts')`, poore layout me nahi — baqi 100+ safhe achhoote |

---

## 8. Guards

`tests/MySql/SalesAnalyticsMySqlTest.php` — asli HTTP route par:

| # | Guard | RED hona chahiye agar... |
|---|---|---|
| 1 | **Safhe ka jama = `overview()['net_sales']`** usi daur par | koi naya hisaab likh do |
| 2 | Owner ko 200 | — |
| 3 | **Cashier/Manager ko 403** | permission kisi aur ko de do |
| 4 | Khali daur → 200 + "koi sale nahi", 500 nahi | khali array par crash |
| 5 | Pichla daur khali → growth par saaf jawab, `∞%`/`NaN` nahi | batta sifar ka intezam hata do |
| 6 | Returns ghatay jayen (partially_returned wala bill) | population badal do |
| 7 | Doosri branch ka data na dikhe | branch filter hata do |
| 8 | 6 mahine ka range → 2 second se kam | — |

⚠️ Guard 1 sab se ahem hai: yehi wo cheez rokta hai jo `DASHBOARD-7DAY-POPULATION-1` me hui thi —
ek hi din ka do jagah do jawab.

---

## 9. Kaam ki fehrist

| # | Kaam | Size |
|---|---|---|
| 1 | `SalesAnalyticsController` — range, pichla daur, growth ka hisaab | ~120 satar |
| 2 | Mahine ka rollup (`dailyStats()` ke natije se PHP me) | ~20 satar |
| 3 | Route + Reports menu ka link + dashboard par Owner-only button | ~15 satar |
| 4 | Blade: 7 charts + date range picker + branch filter | UI |
| 5 | ApexCharts sirf isi safhe par load | 1 satar |
| 6 | Guards (§8) | test |

**Koi migration nahi. Koi data tabdeeli nahi. Kisi maujooda safhe ka behaviour nahi badalta.**

---

## 10. Jo NAHI karna

- ❌ Sales ka koi **naya hisaab** likhna. `dailyStats()` / `SalesReportEngine` hi wahid zariya.
- ❌ Charts **dashboard** par daalna — har cashier par bojh.
- ❌ ApexCharts **layout** me load karna — 100+ safhon par bila zaroorat.
- ❌ CDN se library laana — branch ka internet band ho sakta hai.
- ❌ **Do y-axis** wala chart.
- ❌ Additive permission grant chalana — warna "sirf admin" toot jayega.
- ❌ Canonical `pos-saas` me likhna — kaam alag worktree me.

---

## 11. Owner ke faisle darkar

1. **Alag safha** (`/reports/analytics`) manzoor hai, ya waqai dashboard par hi chahiye?
   (Mera mashwara: alag — wajah §4.)
2. **6 mahine ka data abhi hai hi nahi.** Filter banayen phir bhi (aage kaam aayega), ya abhi
   "30 din / 90 din / custom range" rakhen?
3. Saat graph theek hain, ya koi ghair-zaroori hai / koi aur chahiye?
4. **Peak hours** (ghanton ka chart) abhi chahiye ya baad me? (ye alag query maangta hai)
