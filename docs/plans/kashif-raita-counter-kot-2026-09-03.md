# Kashif Food — Raita ka KOT wapas punching counter par (research)

**Tareekh:** 2026-09-03 · **Tenant:** kashiffood (#348) · **Branch:** 1
**Halat:** RESEARCH — prod par abhi kuch nahi badla. Sab read-only probe se.
**Ta'alluq:** `docs/plans/kashif-kot-routing-requests-2026-09-03.md` (commit `1624e2a`) ka **peecha**.

---

## 1. Kya maanga gaya

> "the way biryani beverages kot are printing to the punching counter, the same way raita will go
> to punching counter. currently its KOT is sending to BBQ counter — change to punching counter"

---

## 2. Ye nayi farmaish nahi — pichle badlav ka side-effect hai

Us doc me client ki hidayat thi: **Fresh Salad → BBQ / Grill**. Raita ke bare me wahan saaf likha
tha (line 149):

> "Salad → BBQ … **Raita ko saath nahi le jana chahiye**"

Magar amal me Raita ko Fresh Salad ke saath uthaa kar `Extras → BBQ Sauce` (cat#40) me daal diya
gaya. Cat#40 ka KOT `BBQ / Grill KOT` par jaata hai — is liye Raita bhi BBQ chala gaya. Us doc ki
line 325 me ye natija darj bhi hai, magar theek nahi kiya gaya.

**Pehle Raita `Raita & Salad` (cat#28) me tha, jiska KOT counter par jaata tha.** Yani owner jo
maang rahe hain wo nayi cheez nahi — wohi halat hai jo mere badlav se pehle thi.

---

## 3. Routing kaise faisla karti hai (code se, qiyas nahi)

**(a) Sirf apni category.** `PrintRoutingService::mappedPrinterIds()` line ke product ki
`category_id` par match karta hai — parent tak nahi chadhta. Child ka mapping hi uska poora faisla.

**(b) Terminal-pinned NULL wale ko gira deta hai.** `applyTerminalPrecedence()`: us terminal ka koi
pinned rule ho to **sirf** pinned; warna sirf `terminal_id = NULL` wale. Dono kabhi saath nahi —
taake ek parchi do jagah na chhape.

Isi se "punching counter" ki data-shakl banti hai: **chaar `kot` rows, har terminal → apna counter.**

---

## 4. Aaj ki halat (prod, `is_active` samet)

| Category | KOT kahan | Reminder |
|---|---|---|
| #26 Chicken Biryani | 4 rows, har terminal → apna counter ✅ | apna counter |
| #27 Beverages | 4 rows, har terminal → apna counter ✅ | apna counter |
| **#28 Raita & Salad** | **4 rows, har terminal → apna counter ✅ (sab ON)** | apna counter |
| #29 Extras | ~~4 counter rows~~ **BAND**; sirf `Fastfood KOT` (NULL) | apna counter |
| #40 BBQ Sauce | 1 row → `BBQ / Grill KOT` (NULL) | apna counter |
| #42 Fastfood Sauce | 1 row → `Fastfood KOT` (NULL) | apna counter |

- **Raita (#181)** abhi cat#40 me hai, saath: `3 Different Chatnies` #195, `Extra Skewer` #193,
  `Fresh Salad` #182
- **cat#28 zinda aur khali hai, uske aathon mapping ON hain** — kuch bhi dobara banana nahi parega
- cat#41 `BBQ Sides` (parent 28) bhi khali para hai — pichle plan ka bacha hua khaka, isay haath
  nahi lagana

> ⚠️ Reminder pehle bhi punching counter par jaata tha aur ab bhi. Masla **sirf KOT** ka hai.

---

## 5. Ilaaj — ek khaana

```
products.category_id   Raita (#181):   40  →  28
```

Bas. **Koi nayi category nahi, koi naya mapping nahi, koi code nahi, koi deploy nahi.**
cat#28 ke chaaron `kot` rows terminal-pinned hain, is liye jo counter punch karega wahi chhapega —
theek Biryani/Beverages jaisa.

### Jo raaste band hain (aur kyun)

| Raasta | Kyun nahi |
|---|---|
| Raita ko `Extras` (#29) me daal dein | Extras ke counter rows **band** hain; uska zinda KOT `Fastfood` hai → Raita Fastfood chala jata |
| cat#40 ka mapping counter par mod dein | Pinned rows NULL wale BBQ row ko gira dete → **Fresh Salad, Extra Skewer, Chatnies bhi** BBQ se hat jate |
| Product par printer set kar dein | `products` me koi printer/KOT column hai hi nahi — routing sirf category par hai |

---

## 6. Kya nahi badlega

- **Paisa** — qeemat, bill, GL, stock: kuch nahi hilta
- **Fresh Salad / Extra Skewer / Chatnies** — cat#40 me, BBQ par, jyun ke tyun
- **Reminder** — pehle bhi punching counter, ab bhi
- **Receipt** — routing se koi taalluq nahi
- **Purane bill aur chhap chuki parchiyan** — kuch nahi hota
- **Khatri Biryani** — bilkul achhoot

## 7. Jo NAZAR aayega (owner ko pehle se pata hona chahiye)

1. **POS par `Raita & Salad` ka tab wapas aa jayega** (abhi khali hone ki wajah se gayab hai),
   usme akela Raita. Cashiers ko **Ctrl+F5**.
2. **Report me Raita ka paisa apne sar par wapas** — abhi `Extras` ke sar me ginta hai, badlav ke
   baad `Raita & Salad` ke sar me. **Kul total waisa ka waisa**, sirf jagah badlegi. (Pichla doc:
   Raita+Salad ka 10,800 Extras ke sar par chala gaya tha.)
3. KOT parchi ka label `Extras / BBQ Sauce` se badal kar `Raita & Salad` ho jayega.

## 8. Faisla jo owner ka hai

- Tab ka naam **`Raita & Salad`** hi rahe, ya sirf **`Raita`**? (Salad ab BBQ Sauce me hai, is liye
  purana naam ab poora sach nahi.)
- **`3 Different Chatnies` (#195)** bhi counter par le aana hai? Wo bhi BBQ station ki cheez nahi
  lagti — magar aap ne sirf Raita kaha, is liye chhua nahi jayega jab tak aap na kahen.

---

## 9. Tasdeeq ka tareeqa (badlav ke baad)

1. Chaaron terminals par **dry-run**: Raita wali line ka KOT kis printer par → har baar **usi
   terminal ka counter printer** (koi asli print job queue nahi hoga)
2. Wohi dry-run `Fresh Salad` par → **BBQ / Grill hi aana chahiye**
3. Reminder ka rasta pehle jaisa
4. Report: badlav se pehle/baad `Extras` + `Raita & Salad` ka **jama barabar**
5. POS payload me Raita nayi jagah par

## 10. Wapsi

`products.category_id` wapas 40. Ek khaana. Kuch aur chhua hi nahi jata.

---

# 11. JO WAQ'I KIYA GAYA (2026-09-03, ~20:21 PKT — dukan khuli thi)

Owner ne cat#28 dobara istemal karne ke bajaye **nayi category** maangi:

> "simple cheez hai — new category banalo Raita ki, aur us ko har station pe point kardo"

("station" = counter; owner khud pehle keh chuke hain "punching counter b to station he hain na".)

## Kya badla — teen cheezein, ek transaction me

| # | Badlav |
|---|---|
| 1 | Nayi root category **#44 `Raita`** (`code=RAITA`, `slug=raita`, `sort_order=28`, active) |
| 2 | **8 mapping rows Chicken Biryani (cat#26) se NAKAL** — sirf `category_id` badla. 4 × `kot` + 4 × `reminder`, har terminal → apna counter printer |
| 3 | `products.category_id` — Raita (#181): **40 → 44** |

Mappings ko **nakal** karne ki wajah: `order_type`, `reminder_confirm_on_addition`, `is_active`
jaisa koi column mere haath se reference se alag na ho jaye.

## Saboot (asli `PrintRoutingService` se, koi print job queue nahi hua)

```
                 PEHLE                    BAAD ME
Raita  @ Delivery      BBQ / Grill KOT  →  T1 Counter Printer
Raita  @ DTQ 2         BBQ / Grill KOT  →  T2 Counter Printer
Raita  @ DTQ 3         BBQ / Grill KOT  →  T3 Counter Printer
Raita  @ DTQ Floor T4  BBQ / Grill KOT  →  T4 Counter Printer

Fresh Salad @ chaaron  BBQ / Grill KOT  →  BBQ / Grill KOT   (be-harkat)
```

> ⚠️ **Probe khud pehle jhoot bol raha tha.** `kotRoutesForSale()` me
> `qtyToPrint = quantity − kot_sent_quantity`, jo chhap chuke purane bill par 0 hai — line skip ho
> jaati thi aur probe "koi route nahi" bolta tha, Fresh Salad par bhi. `isReprint = true` dene par
> asli rasta nazar aaya. Pehla natija phenk diya gaya.

## Paisa

```
PEHLE:  Extras 113,515.00  (qty 281)
BAAD:   Extras 102,915.00  (qty 175)  +  Raita 10,600.00  (qty 106)  =  113,515.00  (qty 281)
```

Bilkul barabar. **Kul jama +600 hua, magar wo mere badlav se nahi:** bill #1612
(`HS-20260903144759-919`, bana 14:47) badlav ke **do second baad** 15:21:48 par paid hua, uski line
`Chicken Tikka (Leg)` 600.00. Dukan chal rahi thi. Chalti dukan me "kul jama" saboot nahi banta —
saboot wo hai ke **Extras + Raita ka jama purane Extras ke barabar hai**.

## Aur

- Khuli bill par Raita **koi nahi** thi, is liye beech-e-service koi parchi mu'attal nahi hui
- `Fresh Salad`, `Extra Skewer`, `3 Different Chatnies` — cat#40 me, BBQ par, jyun ke tyun
- Reminder pehle bhi punching counter par tha, ab bhi
- Purane page khule cashier ko Raita abhi bhi `BBQ Sauce` ke neeche dikhega, magar **KOT phir bhi
  counter par hi jayega** — routing server par print ke waqt hoti hai, page ke payload se nahi.
  Nayi `Raita` pill dekhne ke liye **Ctrl+F5**.

## Bacha hua kachra (owner ke faisle ka muntazir)

`Raita & Salad` (#28) ab khali hai magar **`is_active = 1`** hai aur uska `sort_order` bhi **28** —
yani nayi `Raita` ke saath barabar. POS aur report dono me wo dikhta nahi (khali hai), is liye abhi
koi nuqsan nahi. Kahen to `is_active = 0` kar diya jaye — ek khaana, wapas mor-ne layak.

---

# 12. USI SHAAM DO AUR BADLAV (3 Sep, dukan khuli — dono data-only)

## (a) Naya product: `BBQ Sauce` — PKR 80, KOT BBQ par

Owner: *"1 new item banega BBQ Sauce k name se 80 rps ka jis ki KOT BBQ p nikle gi"*

`Extras / BBQ Sauce` (cat#40) ka KOT pehle hi `BBQ / Grill KOT` par jaata hai, is liye **koi naya
mapping nahi banaya** — product usi category me daal diya. Routing product ki nahi, uski category
ki hoti hai.

Product **#211**, row **Fresh Salad (#182) se NAKAL** — sirf naam/sku/slug/qeemat badle. Nakal is
liye ke pichli baar naya product haath se `simple` + `is_stock_tracked=1` bana diya gaya tha aur POS
par *"out of stock"* aa gaya tha. Sauce `service` / `inventory none` / stock-tracked nahi hai.

## (b) `Singaporean Sauce` ka KOT punching counter par

Owner: *"only Singaporean Sauce will go to punching counter … currently going to Fastfood"*

Wo `Extras / Fastfood Sauce` (cat#42) me tha — magar us category me **Mayo Sauce aur Extra Sauce
bhi** hain, jinhe Fastfood par hi rehna tha. Category ka mapping modna teenon ko hila deta, is liye
Singaporean Sauce ko apni category di: **#45 `Singaporean Sauce`**, aath mapping rows Chicken
Biryani (cat#26) se nakal, product #183 usme.

**Raita se ek farq jaan-boojh kar:** ye `Extras` ka **CHILD** hai, alag root nahi.

| | Raita (#44, root) | Singaporean Sauce (#45, Extras ka child) |
|---|---|---|
| POS | naya top-level tab | wahi `Extras` tab, naya pill |
| Report | apna naya sar; paisa Extras se nikal kar udhar gaya | root `Extras` hi rehta hai — **paisa bilkul nahi hila** |

Child behtar hai jab cheez mantaqi tor par Extras ki hi hai — menu bhi nahi badalta aur report bhi
nahi.

## Tasdeeq (asli `PrintRoutingService`, chaaron terminals)

```
Singaporean Sauce   Fastfood  →  T1 | T2 | T3 | T4     (jo counter punch kare)
Mayo Sauce          Fastfood  →  Fastfood              (be-harkat)
Extra Sauce         Fastfood  →  Fastfood              (be-harkat)
BBQ Sauce (naya)              →  BBQ / Grill           (chaaron par)
```

- Khuli bills par in me se kisi ka KOT baqi nahi tha — beech-e-service koi parchi mu'attal nahi hui
- Cashiers ko **Ctrl+F5**

## Extras ke neeche ab poori tasveer

| Pill | Products | KOT |
|---|---|---|
| Fastfood Sauce (#42) | Mayo Sauce · Extra Sauce | Fastfood |
| BBQ Sauce (#40) | **BBQ Sauce (80)** · Extra Skewer · Fresh Salad · Chatnies *(chhupi)* | BBQ / Grill |
| Singaporean Sauce (#45) | Singaporean Sauce | **punching counter** |
| *(Extras khud, #29)* | Butter · Cheese · Bun · Plain Rice · Arabic Rice · Coleslaw · Dinner Roll · Garlic Fried · Sizzling Charge | Fastfood |

> Reminder chaaron groups me hamesha **punching counter** par jaata hai. Client ki "KOT kahin nahi
> gaya" wali shikayat isi farq se thi: reminder unke saamne aata tha, KOT station par.

## Ek dhyan talab baat

Ab ek **product** `BBQ Sauce` ek **category** `BBQ Sauce` ke andar hai. Kaam theek karta hai, magar
report me dono naam saath aayenge. Category ka naam `BBQ Sides` / `BBQ Extras` karna ho to routing
par koi asar nahi parega — owner ka faisla.
