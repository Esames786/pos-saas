# ITEMS-BY-CATEGORY-1 — Item ka category-wise breakup, apne alag section me

**Tareekh:** 2026-09-03
**Halat:** **RESEARCH ONLY** — kuchh bana nahi, kuchh deploy nahi hua
**Live saboot:** https://claude.ai/code/artifact/7244782a-b9e3-4f7c-bcd0-ff3e7e25f283
**Pichli research jo is se replace hoti hai:** `items-in-category-sequence-2026-09-03.md`
(us me tajweez thi ke **mojooda** Items report ki tarteeb badli jaye — wo tajweez **radd**)

---

## 1. Maang (durust shakl)

> "current item report jaisi hai wo to theek hai — **item + category ek separate report ho**,
> jaise combo separate add kiya hai. Wahan jo breakups aa jayen, taake **purana kaam bhi kharab
> na ho**."

Yani:

- **Mojooda Items section ko haath nahi lagana.** Wohi tarteeb, wohi rows, wohi paisa.
- **Ek naya section** banana — bilkul us tarah jis tarah 2 September ko **Deals** ko apna section
  diya gaya tha (`DEAL-CATEGORY-1`): apna checkbox, apni permission, paanchon renderer par.
- Us naye section me **category ke sar ke neeche breakup** aaye.

Ye pehli tajweez se **behtar** hai: purani report ki shakl mehfooz rehti hai, is liye jo malik
purane hisab se parhta hai us ka kuchh nahi bigadta.

---

## 2. Naya section kya dikhayega

Bari category ka sar → us ke andar chhoti category ka sar → phir item. Khatri, 1 September,
asli data:

```
BEEF KHATRI BIRYANI                        217      118,950.00
   Beef Khatri                             207      111,450.00
      Beef Khatri Biryani (1/2 kg)         163       73,350.00
      Beef Khatri Biryani (1 kg)            27       24,300.00
      Beef Khatri Biryani Special (1 kg)     6        7,200.00
      Beef Khatri Biryani Special (1/2 kg)  11        6,600.00
   Khatri Sadi                               9        2,750.00
      Saadi Khatri Biryani (1/2 kg)          7        1,750.00
      Saadi Khatri Biryani (1 kg)            2        1,000.00
   Matka                                     1        4,750.00
      Beef Biryani Matka (1.5 kg)            1        4,750.00

BEVERAGES                                  225       20,820.00
      Cola Next 300 ml                     136       12,240.00
      Cola Next 500 ml                      27        2,970.00
      Mineral Water (Small)                 34        2,040.00
      ...
```

Do baatein:

1. `BEEF KHATRI BIRYANI 118,950.00` **bilkul wohi hindsa** hai jo Categories section me chhapta
   hai — report khud apna hisab check kar leti hai.
2. Jis category ke andar chhoti categories nahi (Beverages), wahan **beech wali qatar aati hi
   nahi** — faltu sar nahi banta.

---

## 3. Kis kis tenant par nested categories hain

Ye farzi baat nahi, dono live tenants par maujood hai:

| Tenant | Bari category | Us ke andar chhoti (jin me products hain) |
|---|---|---|
| Kashif Food | Continental | Steaks (8), Pasta (8) |
| Kashif Food | Rolls | Beef Boti Rolls, Beef Kebab Rolls, Chicken Roll, Chicken Crispy Rolls, Chicken Malai Boti Roll, Chicken Reshmi Kebab Roll, Ustad Roll |
| Khatri | Beef Khatri Biryani | Beef Khatri (4), Khatri Sadi (3), Matka (2) |
| Khatri | Beef Changezi Pulao | Beef Changezi (4), Changezi Saadi (2) |
| Khatri | Chicken Biryani | Biryani Chicken (5), Biryani Saadi (2) |

Kashif ke 21 sar me se **2** nested hain, Khatri ke 7 me se **3**.

---

## 4. Milaan — live 1 September

| | Kashif Food | Khatri |
|---|---:|---:|
| Categories section | 594,345.00 | 313,320.00 |
| Naya section (Items, deals ke baghair) | 499,640.00 | 313,320.00 |
| Deals section | 94,705.00 | 0.00 |
| **Items + Deals** | **594,345.00 ✅** | **313,320.00 ✅** |

Har bari category ka jama alag alag bhi milaya gaya — Kashif ke 21 me se 20 aur Khatri ke saaton
sar hoo-ba-hoo mel khate hain.

**Sirf "Deals" ka sar farq dikhata hai, aur ye jaan boojh kar hai:** Categories section "Deals" ko
ek sar manta hai (94,705.00), jabke Deals section usay us ke khandanon me torta hai — Midnight,
Platters, Deals, Exclusive Deals, Meal Deal, Pocket Friendly. Yehi shakl purana software chhapta
hai aur yehi 2 September ko tay hui thi. **Is kaam me use haath nahi lagaya jayega.**

---

## 5. Duplication kyun nahi hogi

| Khatra | Kyun nahi |
|---|---|
| Ek item do sar me | Har sale line ki **ek** hi category hoti hai (`lineCategoryExpr()` = `COALESCE(cb.category_id, p.category_id)`), aur har chhoti category ka **ek** hi bara |
| Deal do jagah | Naya section wohi `excludeCombos: true` istemal karega jo mojooda Items karta hai — deal sirf apne Deals section me |
| Item do baar (purane aur naye section me) | Ye **do alag section** hain, ek hi report ke do hisse — jaise Categories aur Items dono mojood hain aur wohi paisa do zaawiyon se dikhate hain. Malik chahe to dono checkbox lagaye, chahe to ek |
| Jama badal jaye | Naya section koi naya hisab nahi karta — wohi rows, sirf tarteeb aur do subtotal ki qatarein |

---

## 6. Kahan kahan banana parega (Deals wala hi raasta)

`DEAL-CATEGORY-1` ne jo 11 files chhui thin, ye kaam bhi wohi shakl le ga:

| # | Jagah | File |
|---|---|---|
| 1 | Engine ka naya method | `SalesReportEngine::byCategoryItems()` |
| 2 | Report Center — screen | `SalesReportCenterController::SECTIONS` + `index.blade.php` (checkbox + tab + table) |
| 3 | Report Center — A4 / PDF | `reports/center/print.blade.php` |
| 4 | Thermal parchi | `EscPosPayloadService` |
| 5 | CSV export | `SalesReportExporter` |
| 6 | POS Quick Report | `PosQuickReportController::SECTIONS` + `pos/index.blade.php` |
| 7 | Raat 2:30 wali email | `SalesReportDocumentService` (wohi engine, khud-ba-khud) |
| 8 | Permission | migration — `tenant.reports.center.sections.category-items`, sirf un roles ko jin ke paas pehle se `sections.items` hai (bilkul jaise `deals` ke waqt kiya tha) |
| 9 | Guard test | naya `ItemsByCategoryMySqlTest` |

**Thermal (42 column)** sab se tang jagah hai: bara sar bold, chhota sar ek indent, item do indent
— ya thermal par sirf bara sar. Ye malik tay karein.

---

## 7. Khatra

| | |
|---|---|
| Mojooda Items / Deals / Categories section | **bilkul nahi badlega** — naya section un ke barabar khara hota hai |
| Migration | ek, sirf permission ke liye (additive) |
| POS / KOT / printing ka raasta | **chhoo-ta hi nahi** |
| Paisa | ek rupya nahi hilega — saboot deploy se pehle/baad ka snapshot milaan (wohi tareeqa jo `REPORT-DEAL-IDENTITY-1` me chala) |
| Default | naya section **band** rahega jab tak malik checkbox na lagaye → purani report jyun ki tyun jati rahegi |
| Wapas palatna | display-only + ek permission row; ek revert kaafi |

---

## 8. Faisle jo aap ke hain

1. Section ka naam — **"Items by Category"** theek hai, ya "Category Breakup" / kuchh aur?
2. Har sar ke andar item **paise ke hisab se** (bara pehle) ya **naam ke hisab se**?
   (mashwara: paisa, mojooda Items section ki tarah)
3. Thermal par chhota sar dikhe ya sirf bara?
4. Raat 2:30 wali email me ye section **shuru se shamil** ho, ya pehle screen par dekh kar faisla?
   (mashwara: pehle screen par dekh lein, phir email me daalein)

Jawab milte hi banana shuru kar dunga — paanchon renderer, guard test, aur live data se tasdeeq.
