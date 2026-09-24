# KF-PULAO-SUBCAT-1 — The Kashif Foods: "Chicken Pulao" → "Pulao" + Chicken / Beef

**Tareekh:** 2026-09-17
**Halat:** ✅ **LAGA DIYA** — prod data (koi code change nahi, koi deploy nahi)
**Tenant:** `tawakalkashif`, **branch 1 = The Kashif Foods**

**Maalik ka mutalba:** "change cat name chicken pulao to pulao and add two sub category
chicken and beef, move chicken into chicken and beef to beef and printer mapping accordingly
since only one printer attach" + "agar parent category say print chal gae ga to extra
mapping na dalna"

---

## 1. Jo kiya

| | pehle | ab |
|---|---|---|
| `cat4` ka naam | Chicken Pulao | **Pulao** |
| `cat23` (naya) | — | **Chicken** — `parent_id=4`, branch 1 |
| `cat24` (naya) | — | **Beef** — `parent_id=4`, branch 1 |

**Chicken (cat23) me gaye — 4:** #17 Chicken Pulao, #18 Chicken Pulao (1 KG),
#19 Chicken Pulao (6 Pcs Family Pack), #97 Chicken Pulao 400 Gram

**Beef (cat24) me gaye — 2:** #101 Beef Pulao Single, #102 Beef Pulao (Half KG)

**Parent (cat4 "Pulao") par hi chhor diye — 6:** #15 Sada Pulao, #16 Sada Pulao (1 KG),
#20 Extra Piece (Pulao), #72 Extra Boti, #99 Chana Pulao 100, #100 Chana Pulao 150

> Ye 6 na chicken hain na beef. Mutalba "chicken ko chicken me, beef ko beef me" tha, is liye
> baaqi ko parent par hi rakha. Parent category products SEEDHE rakh sakti hai aur saath child
> bhi — `cat1 Singaporean Rice` bilkul aise hi chal rahi hai (apne products + child `cat18`).
> Agar in me se kisi ki apni sub-category chahiye (jaise "Chana" ya "Extras") to bata dein.

**`code` aur `slug` ko haath nahi lagaya** (`KF-CHICKENPULAO` / `chicken-pulao-kf`). Sirf naam
badla. Code import-matching ki kunji ho sakta hai; use badalne se kuch haasil nahi tha aur
todne ka andesha tha.

---

## 2. Printer mapping — QASDAN koi nahi daali

Maalik ka sawaal theek nishane par tha, aur jawab do hisson me hai:

**(a) Parent se print NAHI chalta.** `PrintRoutingService::mappedPrinterIds()` sirf product ki
apni (leaf) `category_id` matching karta hai, ya `category_id IS NULL` wala "All categories"
wildcard. **Parent ki zanjeer nahi chalta.** Yani agar is tenant par mappings hoti, to naye
Chicken/Beef ko apni mapping darkar hoti.

**(b) Magar is tenant par ek bhi mapping maujood NAHI** — `category_printer_mappings` = **0 rows**
(poore tenant par). Is liye har KOT line pehle se hi is zanjeer se gir rahi hai:

```
category mapping  →  terminal KOT printer  →  branch default  →  browser fallback
   (0 rows)              (koi nahi)            ✅ yahan rukti hai
```

Branch 1 par sirf **ek** printer hai — `#1 Kashif Foods Counter Printer` (192.168.100.251,
`is_default=1`). Move ke baad Chicken/Beef par bhi koi mapping nahi, to wohi fallback, **wohi
printer, wohi bartaao.** Mapping daalne se kuch behtar nahi hota — sirf ek aisi qaid barh jati
jise aage badalna parta.

---

## 3. Reports — root ka total bilkul nahi hila (SABIT)

Ye is kaam ka sab se bara andesha tha. `SalesReportEngine::lineCategoryExpr()` =
`COALESCE(cb.category_id, p.category_id)` — yani report **zinda** product ki category parhti hai,
sale line par category ka koi snapshot NAHI hai. To product ki category badalne se **purani
bikri ki grouping bhi badal jati hai.**

Bachao ye hai ke `byCategory()` `rootMap()` (recursive CTE) se tree banati hai aur har cheez ko
uske ROOT tak jama karti hai. Chicken/Beef ko `cat4` ke NEECHE rakhne se root wohi `cat4` raha —
is liye top-level total waisa hi hai, sirf breakdown bareek ho gaya.

Prod par asli report se tasdeeq (branch 1, 01–17 Sep):

```
Pulao                 net = 182,260      ← pehle "Chicken Pulao" ka bhi yehi tha
     ↳ Chicken             156,270
     ↳ Beef                  5,050
     ↳ Pulao                20,940
                          ─────────
                           182,260  ✅
```

⚠️ **Agar Chicken/Beef ko ROOT category banaya jata** (parent ke baghair) to purana "Chicken
Pulao" wala head toot kar teen alag heads ban jata aur mahine-ba-mahine moqabla be-maani ho
jata. Isi liye dono ko `cat4` ke neeche rakha gaya.

---

## 4. POS — kuch nahi tootta

`POSController` root categories (`parent_id IS NULL`) ke pills banata hai, aur pill tab dikhta
hai jab **khud ya kisi child** me grid-visible product ho. Blade me
(`index.blade.php`, KHATRI-MENU-2):

> "a parent pill matches its OWN products AND its children's products"

Yani **"Pulao" pill par saare 12 items nazar aayenge**, aur uske neeche child strip
(`#child-category-strip`) me **Chicken / Beef** ke button aayenge jin se cashier chhaant sakta hai.
Ye Khatri ka aazmaya hua pattern hai (wahan menu "Saada"/"Non-Saada" child categories me hai) aur
isi tenant par `Beverages → Soft Drinks / Mineral Water / Juice & Can` bhi yehi kar raha hai.

---

## 5. Risk — jo check kiya

| khatra | haqeeqat |
|---|---|
| paisa | `tb_diff = 0.00`. Category move kisi journal, stock ya sale line ko chhoota hi nahi. |
| purani reports ka total | **be-harkat** — upar sabit kiya (182,260 = 182,260) |
| KOT galat printer par | mumkin hi nahi — 0 mappings, sab branch default par, aur branch 1 par printer ek hi hai |
| combos | `cat4` par 0 combos, aur in 12 products me se koi bhi kisi combo ka component nahi (0 rows) |
| translations | `category_translations` par cat4 ki 0 rows |
| branch 2 | `cat9 Chana Pulao` / `cat10 Beef Pulao` alag categories hain, branch 2 se bandhi — un par koi asar nahi |
| khuli bills | sale line apna `unit_price` khud rakhti hai; category line par mehfooz hoti hi nahi |

**Wapas palatna aasan hai:** cat4 ka naam wapas, 6 products ki `category_id` wapas 4, aur cat23/24
delete. Koi migration nahi, koi numbering nahi chhidi.

---

## 6. Cashier ke liye

**Ctrl+F5 zaroori hai.** POS category payload page load par aata hai, is liye jin counters par
screen khuli hai wahan purana "Chicken Pulao" pill hi dikhega aur child strip nahi aayegi.
