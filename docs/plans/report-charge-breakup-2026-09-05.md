# "Plus Delivery & Other Charges" ko naam dena (research)

**Tareekh:** 2026-09-05 · **Halat:** RESEARCH — koi code nahi badla.

---

## 1. Kya maanga gaya

> "Delivery + other charges alag hone chahiyen. Agar delivery charges hain to neeche delivery
> charges dikhao; agar koi aur charge hai to uska naam aur uska total. Mila kar mat dikhao. Ye fix
> har us jagah karni hai jahan report total jama karti hai aur neeche breakup dikhati hai."

---

## 2. Ye line abhi bantee kaise hai

`resources/views/tenant/reports/center/print.blade.php` — `$bridgeRows`:

```php
$netCharges = round($bridgeNetSales - $lineNet - $deals, 2);
```

Ye **jama nahi, bacha hua (residual)** hai: NET SALES me se section ka apna total minus deals.
Jo bhi bache, usay "Plus Delivery & Other Charges (net)" ka naam de diya jaata hai.

**Ye jaan-boojh kar aisa banaya gaya tha**, aur wajah doc me likhi hai: *"The gap is derived as
(NET SALES − line net), so it ALWAYS closes — the old delivery-only figure silently vanished the
moment a discount/tax existed."* Yani agar sirf `delivery_charge` chhapte to jis din tax ya service
charge hota, section NET SALES tak pahunchta hi nahi aur farq **chup-chaap gum** ho jata.

**Is ki tareekh:**

| Commit | Kya kiya |
|---|---|
| `719b4bb` + `24f6c29` (21 Aug) | REPORT-CHARGE-BRIDGE-1/2 — ye bridge banaya, taake merchandise total NET SALES tak pahunche |
| `11fbe7f` / `505d218` (3 Sep) | BRIDGE-DEALS-1 — Kashif par ye line **95,859** dikha rahi thi jab asli charges **4,369** the; baqi 91,490 deal ka paisa tha jo ghalat naam pehne hue tha. Deals ko apni line mili |

Yani ek dafa isay pehle bhi tor kar theek kiya ja chuka hai — **ab bhi wohi bimari baqi hai, bas
chhoti shakl me**: chaar mukhtalif cheezein ek naam ke neeche hain.

## 3. Us ek line ke andar waqai kya hai

| Cheez | Overview me alag dikhti hai | Bridge me alag dikhti hai |
|---|---|---|
| Delivery Charge | ✅ "Plus Delivery Charge" | ❌ mili hui |
| Tips | ✅ "Plus Tips" | ❌ mili hui |
| Tax | ✅ "Plus Tax" | ❌ mili hui |
| Service Charge | ✅ "Plus Service Charge" | ❌ mili hui |
| Delivery jo return par wapas gaya | — | ❌ mili hui |

**Achhi khabar:** ye saare figures **pehle se maujood hain**. `SalesReportDocumentService` bridge
me poora overview bhejta hai (`'bridge' => $summary + ['deals_net' => ...]`), aur summary me
`delivery_charge`, `delivery_refunded`, `tips`, `tax`, `service_charge` sab hain. Blade unhein
istemal hi nahi karti.

**Yani bade sections ke liye engine ko haath lagane ki zaroorat NAHI — sirf blade ka kaam hai.**

## 4. Tajweez

Ek residual line ki jagah, **naam wali lines** — aur aakhir me ek residual jo sirf tab chhape jab
waqai kuch bacha ho:

```
Plus Delivery Charge (net)      600.00     ← delivery_charge − delivery_refunded
Plus Tips                        —         ← sifar ho to chhape hi nahi
Plus Tax                         —
Plus Service Charge              —
Plus Other                       —         ← jo bhi bacha, taake hisab HAMESHA band ho
= NET SALES                  16,200.00
```

**Residual line rakhna zaroori hai.** Uske bagair wohi purani bimari lautegi jo `719b4bb` ne theek
ki thi: koi nayi qism ka charge aane par section NET SALES tak nahi pahunchega aur farq chup-chaap
gum ho jayega. Ab wo farq **"Other" ke naam se nazar aayega** — jo saaf hai, aur ye ishara bhi dega
ke kuch naya aa gaya hai jise naam dena chahiye.

## 5. Kahan kahan lagana hai

| Jagah | Call sites | Kaam |
|---|---|---|
| `$bridgeRows` — CATEGORIES, ITEMS, ITEMS BY CATEGORY | **6** (teen section × A4 + thermal) | **Sirf blade** — data pehle se maujood |
| `$otBridge` — BY ORDER TYPE | **4** | ⚠️ **Engine ka kaam** — abhi per-type sirf ek `net_charges` milta hai (`SalesReportEngine:967`), components nahi. Har order type ka delivery/tips/tax/service alag nikalna parega |

## 6. ⚠️ Ek aur cheez jo isi tehqeeq me nikli

**Thermal roll par ye bridge chhapta hi nahi.** `EscPosPayloadService::buildReport()` me
CATEGORIES / ITEMS / ITEMS BY CATEGORY / DEALS sab apna `TOTAL` aur `GRAND TOTAL` chhapte hain,
magar **NET SALES tak koi bridge nahi**. Yani roll par section ka total NET SALES se milta hi
nahi, aur parhne wale ko pata nahi chalta ke farq kyun hai.

Ye alag masla hai magar isi kaam ke saath karna behtar hoga — warna A4 aur roll do alag kahaniyan
sunayenge, jo isi hafte report ke preview par ho chuka hai.

## 7. Jo NAHI badlega

- **Koi hindsa nahi** — sirf ek line ki jagah chaar naam wali lines. `= NET SALES` wahi rahega,
  aur `Plus Other` ki wajah se jama hamesha barabar rahega
- OVERVIEW section — wo pehle hi har cheez alag dikhata hai
- Koi query, koi migration, koi routing

## 8. Tasdeeq ka tareeqa

1. Har section par: naam wali lines ka **jama = purana residual**, ek paisa idhar udhar nahi
2. `= NET SALES` badlav se pehle aur baad **barabar**
3. Aisa din chun kar dekhna jis me delivery + tips + tax teenon hon (warna test kaat hi nahi sakta)
4. Guard: ek aisa charge daal kar jise koi naam na mile — **"Plus Other" me nazar aana chahiye**,
   gum nahi hona chahiye
5. Dono raaste — A4 aur thermal — ka muqabla

## 9. Faisla owner ka

- Lines ke naam: `Plus Delivery Charge (net)` theek hai, ya sirf `Delivery Charges`?
- Sifar wali lines chhupani hain (mera mashwara: haan) ya sifar ke saath dikhani hain?
- Thermal roll par bhi bridge lagana hai (§6)? Mera mashwara: haan, saath hi.

---

# 10. Poora naqsha — kaunsi screen, kahan se banti hai

Poore system me ye line **sirf EK file** me hai:
`resources/views/tenant/reports/center/print.blade.php` — do helper (`$bridgeRows`, `$otBridge`),
**10 call sites**.

Magar wo ek file **paanch outputs** chalati hai. Yani ek jagah theek karne se paanchon theek honge:

| # | Screen / output | Kahan se banta hai | Line abhi hai? |
|---|---|---|---|
| 1 | **Report Center → A4 / PDF** | `SalesReportCenterController:238` + `SalesReportDocumentService:46` | ✅ mili hui |
| 2 | **Report Center → Thermal preview** | wahi blade, `mode=thermal` | ✅ mili hui |
| 3 | **Z Report (End of Day)** | ye alag renderer **nahi** — Report Center ka *preset* hai (`?preset=z`) | ✅ mili hui |
| 4 | **POS Quick Report** (screen + thermal print) | `PosQuickReportController:139` → wahi blade | ✅ mili hui |
| 5 | **Quick Report ka email + raat wala schedule** | `ReportScheduleService:126` → `SalesReportDocumentService::pdf()` → wahi blade | ✅ mili hui |

## 11. Aur teen jagahen jahan ye line HAI HI NAHI

Ye us se bhi bara masla ho sakta hai: wahan section ka total **NET SALES tak pahunchta hi nahi**,
aur parhne wale ko farq ka pata bhi nahi chalta.

| # | Screen | Faili | Halat |
|---|---|---|---|
| 6 | **Report Center ki apni screen** | `reports/center/index.blade.php` | ❌ **koi bridge nahi** — sirf OVERVIEW me Net Sales hai; sections ke totals bina bridge ke khade hain |
| 7 | **Thermal roll / Send-to-Network** | `EscPosPayloadService::buildReport()` | ❌ **koi bridge nahi** — CATEGORIES/ITEMS/DEALS apna TOTAL aur GRAND TOTAL chhapte hain, bas |
| 8 | **CSV export** | `SalesReportExporter` | ❌ koi bridge nahi (CSV me shayad zaroori bhi na ho — owner ka faisla) |

> ⚠️ **#7 sab se ahem hai.** Roll par jo parchi counter se nikalti hai us par section ka total NET
> SALES se milta hi nahi. A4 par bridge hai, roll par nahi — dono alag kahani sunate hain. Isi
> hafte report ke preview aur printer ke darmiyan bilkul yehi farq nikla tha.

## 12. Kaam ka andaza

| Hissa | Kahan | Kitna |
|---|---|---|
| **A** | `print.blade.php` ke `$bridgeRows` — teen sections | Sirf blade; data pehle se maujood. **Paanch outputs ek saath theek** |
| **B** | `print.blade.php` ke `$otBridge` — BY ORDER TYPE | Engine ka kaam: `SalesReportEngine:967` per type sirf `net_charges` deta hai, uske hisse nahi |
| **C** | `EscPosPayloadService::buildReport()` | Roll par bridge banana — abhi hai hi nahi |
| **D** | `reports/center/index.blade.php` | Screen par bridge banana — abhi hai hi nahi |

Mera mashwara: **A + C ek saath** (A4 aur roll ek jaisa bolen, aur paanchon outputs durust), phir
**B**, phir **D**. Har hisse ka apna guard, aur dono raaston ka muqabla — warna wohi drift.
