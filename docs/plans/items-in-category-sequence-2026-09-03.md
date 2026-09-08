# ITEM-CATEGORY-SEQUENCE — Item report category ki tarteeb me, purane software ki tarah

**Tareekh:** 2026-09-03
**Halat:** **RADD (superseded)** — is me tajweez thi ke *mojooda* Items report ki tarteeb badli jaye.
Malik ne behtar shakl chuni: purani report ko haath na lagao, **naya alag section** banao (jaise Deals bana tha).
Dekhein → `items-by-category-section-2026-09-03.md`. Ye file sirf research ke record ke liye rakhi hai
(nested categories ki naap aur teen raaston ka moazna is me hai).
**Tenant:** Kashif Food (#348) + Khatri (#212), live data 1 September

---

## 1. Maang

Purane software ka Z report **item ko category ki tarteeb me** chhapta hai — har category ka sar
(head), us ke neeche us ke item, aur har head ka apna jama. Hamara Items section abhi **paise ke
hisab se ooper se neeche** aata hai, category sirf ek column me likhi hoti hai.

Shart: **koi duplication na ho**, aur **deal item me dobara na aayen** — jaisa 1–2 September ke
kaam me tay hua tha (`REPORT-DEAL-IDENTITY-1`, `DEAL-CATEGORY-1`).

---

## 2. Abhi kya hota hai

`SalesReportEngine::byItem()` har row ke saath us ki category ka naam bhi laata hai
(`MAX(c.name) as category`), magar tarteeb `net_value` ke hisab se ulti (ya `qty` / `alpha`).
Kashif Food, 1 September:

```
Singaporean Rice (Regular)            [Singaporean Rice]   206   123,600.00
Singaporean Rice (Large)              [Singaporean Rice]    54    56,700.00
Chicken Biryani (Small)               [Chicken Biryani]     78    31,200.00
Soft Drink (345 ml)                   [Beverages]          209    25,080.00
Chicken Alfredo Pasta (1 Person)      [Pasta]               12    15,000.00
...  kul 108 rows
```

Deal pehle hi Items se nikal chuke hain (`byItem(..., excludeCombos: true)`) aur apne section me
jaate hain — **ye hissa pehle se durust hai, is kaam me use chhera nahi jayega.**

---

## 3. Asal masla jo research me nikla: **sar (head) root hoga ya leaf?**

Ye sab se ahem baat hai aur ye faisla malik ka hai.

Categories section (`byCategory`) har cheez ko **root** category par file karta hai — recursive CTE
`rootMap()` se. Lekin Items ke row me jo category likhi hoti hai wo **leaf** hai. Dono tenants par
ye farq **asal me maujood hai**, farzi nahi:

| Tenant | Root category | Us ke andar leaf categories (jin me products hain) |
|---|---|---|
| Kashif Food | Continental | Steaks (8), Pasta (8) |
| Kashif Food | Rolls | Beef Boti Rolls, Beef Kebab Rolls, Chicken Roll, Chicken Crispy Rolls, Chicken Malai Boti Roll, Chicken Reshmi Kebab Roll, Ustad Roll |
| Khatri | Beef Khatri Biryani | Beef Khatri (4), Khatri Sadi (3), Matka (2) |
| Khatri | Beef Changezi Pulao | Beef Changezi (4), Changezi Saadi (2) |
| Khatri | Chicken Biryani | Biryani Chicken (5), Biryani Saadi (2) |

**Agar sirf leaf par group karein**, to Items ka har head Categories section se **mel nahi khayega**.
Live naapa gaya, Kashif 1 September:

```
Head                      Categories        Items      milaan
Continental               23,250.00          0.00      >>> FARQ 23,250.00   (Steaks/Pasta alag heads ban gaye)
Rolls                     26,810.00          0.00      >>> FARQ 26,810.00   (7 roll heads alag ban gaye)
Bar-B-Que                 48,700.00     48,700.00      OK
Singaporean Rice         192,940.00    192,940.00      OK
```

Khatri par teen sab se bari categories isi surat me hain (118,950 + 91,750 + 37,210).

**Kul jama phir bhi durust rehta hai** — `Categories 594,345 = Items 499,640 + Deals 94,705` —
farq sirf ye hai ke paisa kis sar ke neeche dikhta hai.

---

## 4. Teen raaste

### Raasta A — sirf ROOT par group

Har item apni root category ke neeche. Har head ka jama **bilkul** Categories section ke barabar.
Nuqsan: Pasta / Steaks / saat roll ki tafseel gum, sab "Continental" aur "Rolls" me sama jate hain.
(Waise Categories section me abhi bhi malik ko ye tafseel nazar nahi aati.)

### Raasta B — sirf LEAF par group

Pasta, Steaks, saat roll — sab apne apne sar. Tafseel sab se zyada. Nuqsan: Items ke head aur
Categories ke head ek doosre se mel nahi khayenge — malik do jagah do alag naam parhega.

### Raasta C — ROOT sar, us ke andar LEAF sar, phir item  ← **mera mashwara**

Dono cheezein ek saath: milaan bhi, tafseel bhi. Khatri, 1 September, asli data par bana kar dekha:

```
BEEF KHATRI BIRYANI                        217      118,950.00
   Beef Khatri                             207      111,450.00
      Beef Khatri Biryani (1/2 kg)          163       73,350.00
      Beef Khatri Biryani (1 kg)             27       24,300.00
      Beef Khatri Biryani Special (1 kg)      6        7,200.00
      Beef Khatri Biryani Special (1/2 kg)   11        6,600.00
   Khatri Sadi                               9        2,750.00
      Saadi Khatri Biryani (1/2 kg)           7        1,750.00
      Saadi Khatri Biryani (1 kg)             2        1,000.00
   Matka                                     1        4,750.00
      Beef Biryani Matka (1.5 kg)             1        4,750.00

BEVERAGES                                  225       20,820.00
      Cola Next 300 ml                      136       12,240.00
      Cola Next 500 ml                       27        2,970.00
      Mineral Water (Small)                  34        2,040.00
      ...
```

`BEEF KHATRI BIRYANI 118,950.00` — bilkul wohi hindsa jo Categories section me hai. Aur jis
category ke bache nahi (Beverages), wahan beech ki qatar aati hi nahi — faltu sar nahi banta.

---

## 5. Duplication — kyun nahi hogi

| Khatra | Kyun nahi |
|---|---|
| Ek item do heads me | Har sale line ki **ek** hi category hoti hai (`lineCategoryExpr()` = `COALESCE(cb.category_id, p.category_id)`), aur har leaf ka **ek** hi root. Grouping sirf tarteeb badalti hai, rows nahi banati |
| Deal item me bhi, Deals me bhi | `byItem(..., excludeCombos: true)` deal ko Items se nikal deta hai — ye pehle se live hai aur is kaam me chhera nahi jayega |
| Jama badal jaye | Ye **sirf display** hai. Wohi rows, wohi hindse, sirf tarteeb aur do subtotal ki qatarein. Rupya ek bhi nahi hilega |
| Do jagah do alag jama | Raasta C me har root ka jama Categories section ke barabar rehta hai — report khud apna hisab check karti hai |

---

## 6. Kahan kahan lagana parega (koi screen na chhutay)

`byItem()` ko **paanch** jagah se bulaya jata hai — pichhle kaam ka sabaq yahi tha ke ek bhi
renderer chhoot jaye to malik ko adhoori report jati hai:

| # | Jagah | File |
|---|---|---|
| 1 | Report Center — screen | `SalesReportCenterController::data()` → `reports/center/index.blade.php` |
| 2 | Report Center — A4 print / PDF | `…/print.blade.php` |
| 3 | Thermal parchi | `EscPosPayloadService` (`$has('items')`) |
| 4 | CSV export | `SalesReportExporter::itemsCsv()` |
| 5 | POS Quick Report + raat 2:30 wali email | `SalesReportDocumentService` (wohi engine, wohi shakl) |

Thermal (42 columns) sab se tang jagah hai — wahan root sar **bold**, leaf sar ek indent, item
do indent — ya leaf sar chhod kar sirf root, ye malik tay karein.

---

## 7. Deals section ka kya banega

**Kuchh nahi badlega.** Categories section "Deals" ko ek root sar ke taur par dikhata hai
(94,705.00), aur Deals section usi ko us ke khandanon me torta hai — Midnight, Platters, Deals,
Exclusive Deals, Meal Deal, Pocket Friendly. **Ye jaan boojh kar hai** aur bilkul wohi shakl hai
jo purana software chhapta hai. Is kaam me use haath nahi lagaya jayega.

---

## 8. Ek aur faisla: default kya ho

- **Report Center** me abhi Sort ka option hai (Net / Qty / Returns / Alpha). Is me **Category**
  ek naya option ban sakta hai — tab kuchh bhi khud se nahi badlega.
- Lekin **thermal, CSV, Quick Report aur raat wali email** me koi option nahi hota — wahan ek
  default tay karna parega.

Chunke maang hi ye hai ke report purane software jaisi lage, mera mashwara: **category sequence
sab jagah default ho**, aur Report Center me purane sort options bhi maujood rahen (jise paise ke
hisab se dekhna ho wo badal le).

⚠️ Iska matlab ye hai ke **raat 2:30 wali email ki shakl badal jayegi** — hindse wahi rahenge,
tarteeb nayi hogi. Malik ko pehle se bata dena chahiye.

---

## 9. Khatra kitna hai

| | |
|---|---|
| Migration | koi nahi |
| Route / permission | koi nahi |
| POS / KOT / printing ka koi raasta | **bilkul nahi chhoo-ta** — ye report-side ka kaam hai |
| Paisa | ek rupya nahi hilega; saboot deploy se pehle aur baad ka **snapshot milaan** hoga (wohi tareeqa jo `REPORT-DEAL-IDENTITY-1` me istemal hua: ITEM ke ilawa ek line na badle) |
| Wapas palatna | display-only, ek revert kaafi |

---

## 10. Faisla jo aap ka hai

1. **Raasta A, B ya C?** (mera mashwara: **C**)
2. Har head ke andar item **paise ke hisab se** (bara pehle) ya **naam ke hisab se**? (mashwara: paisa)
3. Category sequence **sab jagah default** ho, ya sirf Report Center ka ek option?
4. Thermal par leaf sar dikhen ya sirf root (42 column ki tangi ki wajah se)?

Jawab milte hi implementation ka plan aur guard test likh dunga — aur pehle ki tarah paanchon
renderer par live data se tasdeeq.
