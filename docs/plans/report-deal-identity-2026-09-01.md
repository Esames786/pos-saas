# REPORT-DEAL-IDENTITY-1 — deal apne naam se dikhe, aur apni jagah par

**Status:** RESEARCH / PLAN — banaya nahi gaya
**Date:** 2026-09-01
**Raised by:** owner — "kya waqai Singaporean Rice Regular Midnight itna bika?"
**BEFORE snapshot:** `docs/snapshots/report-sections-before-2026-09-01.txt` (506 lines, dono
tenants, 30+31 Aug, har section) — fix ke baad wahi script chala kar diff karna hai

---

## 1. Sab se pehle: **paisa aur ginti durust hai**

Naapa gaya, Kashif 31 Aug:

```
items ka total (abhi, product par group)   : 465,450.00
har naam alag kar dein to bhi total        : 465,450.00
farq                                        :      0.00
```

Grouping badalne se **jama nahi badalta** — sirf qatarein alag hoti hain. NET SALES, category
totals, cash/bank — sab jyon ke tyon.

## 2. Masla kya hai

Deal ka apna product nahi hota. Bikte waqt uska `combo_header` line **kisi mojooda product par**
baith jata hai — aksar apne hi kisi component par. Report `product_id` par group karti hai aur naam
`MAX(product_name)` se chunti hai, to poori qatar us ek naam ke neeche chali jaati hai.

Kashif, 31 Aug — **7 rows** aisi milin:

| product | qty | paisa | report me naam | asal me is me kya kya hai |
|---|---|---|---|---|
| #158 | 196 | 122,580 | *Singaporean Rice (Regular) (Midnight)* | Regular 160 + **Midnight 30** + Deal 5/6/12/13 |
| #165 | 35 | 14,475 | *Deal 1 (Serve 1)* | Chicken Biryani (Small) + Deal 1 |
| #87 | 10 | 12,000 | *Chicken Chow Mein + Drink (Midnight)* | Chow Mein + Midnight wala |
| #31 | 12 | 10,555 | *Deal 9 (Serve 2)* | Zinger Burger (With Fries) + Deal 9 |
| #30 | 9 | 6,450 | *Chicken Zinger Burger + Fries + Drink (Midnight)* | teen alag cheezein |
| #113 | 5 | 5,750 | *Chicken Malai Boti + Drink (Midnight)* | Malai Boti + Midnight |
| #50 | 2 | 1,750 | *Chicken Bar-B-Que Sandwich* | Sandwich + Midnight wala |

Owner ka sawal isi ka nateeja tha: **Midnight sirf 30 bika, 196 nahi.**

## 3. Kahan kahan ye confusion aa sakti hai

Poora engine scan kiya. Sirf wo sections mutasir hain jo **product par group** karte hain:

| Section (report ka tab) | Product par group? | Asar |
|---|---|---|
| **Items** | ✅ haan | **naam ghalat** — 7 rows |
| **Cancellations** | ✅ haan | wahi naam wala masla |
| Overview | ❌ | sirf jama — theek |
| Categories | ❌ | category par group — magar **alag masla, neeche dekhein** |
| Waiters | ❌ | theek |
| Order Types / By Order Type | ❌ | theek (magar andar `byItem` chalta hai → wirse me theek hoga) |
| Departments | ❌ | theek |
| Details | ❌ | har line ka apna naam — **yahan sach dikhta hai** |
| Cash & Bank | ❌ | paise ke raaste — product se lena dena nahi |

## 4. Categories me ek **alag** masla — jagah ka

Ye naam ka nahi. Deal ka paisa **uske apne category me nahi jaata**, uske component ki category me
chala jaata hai:

| Deal | Paisa | Report kis category me daalti hai | Deal ki apni category |
|---|---|---|---|
| Singaporean Rice Khass | 20,300 | Bar-B-Que | **Platters** |
| Rice (Regular) Midnight | 16,500 | Singaporean Rice | **Midnight** |
| Deal 5 (Serve 2) | 4,860 | Singaporean Rice | **Deals** |
| Chow Mein + Drink (Midnight) | 4,800 | Chinese | **Midnight** |
| Classic Platter 3 | 3,300 | **Extras** | **Platters** |
| Deal 9 (Serve 2) | 1,755 | Burgers | **Deals** |

Nateeja: **"Midnight" category ki apni bikri report me kahin nazar nahi aati** — wo Singaporean
Rice, Chinese, Burgers waghera me bikhri hui hai. Isi tarah "Deals" aur "Platters" bhi.

## 5. Do alag fix — ek mehfooz, ek faisla talab

### Fix A — Items + Cancellations ke naam (MEHFOOZ)

`byItem` (aur `cancellations`) `product_id` **aur** `combo_id` par group karein. Har deal apni
qatar me apne naam ke saath:

```
Singaporean Rice (Regular)                 160    96,000
Singaporean Rice (Regular) (Midnight)       30    16,500
Deal 5 (Serve 2)                             3     4,860
Deal 6 (Serve 2)                             1     1,555
Deal 12 (Serve 2)                            1     1,620
Deal 13 (Serve 2)                            1     2,045
```

⚠️ **Koi paisa kisi row se hilta nahi** — upar sabit kiya (0.00 farq). Sirf 7 rows tootenge.
⚠️ Naam `MAX(product_name)` ke bajaye **deal ka apna naam** (`combos.name`) se aana chahiye, warna
ek deal ke andar bhi naam badalta rahe to phir wahi masla.

#### Returns is fix ka sab se naazuk hissa hain — aur ye chal jayenge

`byItem` returns ko alag query se laata hai aur `product_id:variant_id` par key karta hai. Agar
sold rows combo par baant dein aur returns na baantein, to return kisi ghalat row me chala jayega
ya bilkul gir jayega — aur **wo paisa hilana hoga**. Ye check karna zaroori tha.

**Achhi khabar: `returnLinesBase()` pehle se asli order line se judta hai:**

```php
->join('sales_order_lines as ol', 'ol.id', '=', 'rl.sales_return_line_id ke zariye')
```

Yani har return line ki **asli line** mojood hai, aur usi par `combo_id` hai. To returns bilkul
usi tarah baant sakte hain — koi naya column, koi migration nahi.

Kashif 31 Aug, product #158 ke 5 return — asli data:

| Return kis line se aaya | qty | paisa | fix ke baad kis row me jayega |
|---|---|---|---|
| `combo_id = NULL` (aam sale) | 4 | 2,400 | Singaporean Rice (Regular) |
| `combo_id = 67` (Midnight deal) | 1 | 550 | Singaporean Rice (Regular) (Midnight) |
| **jama** | **5** | **2,950** | **wahi jo report abhi dikhati hai** |

⚠️ **Orphan return lines: 0** — koi return aisa nahi jiski asli line na mile. Phir bhi code me
fallback rakhna hai: agar kabhi asli line na mile to us return ko **bina combo wali row** me daalein,
girayen bilkul nahi. Ek return ka gir jaana paisa badal dega, aur yehi wo cheez hai jo is poore
fix me nahi honi chahiye.

⚠️ Test me ye alag se pin karna hai: **returns ka jama fix se pehle aur baad me barabar rahe**,
aur ek deal-wala return deal ki row me jaye, aam wala aam row me.

### Fix B — Categories ki jagah (FAISLA TALAB)

Deal ka paisa uske **apne category** me daalna. Grand total wahi rahega, magar **category rows ke
beech paisa hilega** — Bar-B-Que se 20,300 nikal kar Platters me jayega, waghera.

⚠️ Purani reports ki shakal badal jayegi. Agar kisi ne 30 Aug ki report chhap kar rakhi hai, wo
dobara nikalne par alag categories dikhayegi (jama wahi). **Ye owner ka faisla hai.**
⚠️ Har deal ka `combos.category_id` set hona chahiye — jin ka nahi hai wo kahan jayenge, ye tay
karna hoga (tajweez: "Deals").

## 6. Kahan kahan apne aap lag jayega

Sab kuch **ek hi engine** se guzarta hai, is liye ek jagah theek karne se ye sab theek honge:

- Report Center — screen
- **A4 PDF** (print/download)
- **Thermal** copy
- **Roz ki email report** (scheduled)
- **POS ka Quick Report**
- Order-type wala section (jo andar `byItem`/`byCategory` chalata hai)
- CSV export

Alag alag kuch nahi karna — bilkul waise hi jaise `REPORT-DEAL-COMPONENTS-1` (`fc5414a`) ek saath
sab par laga tha.

## 7. Test plan

`ReportDealIdentityMySqlTest`:
- ek product par ek standard sale + do alag deals ke headers → **teen alag rows**, teen alag naam
- **jama bilkul wahi rahe** fix se pehle aur baad (yehi sab se ahem)
- deal ka naam `combos.name` se aaye, line ke `product_name` se nahi
- component ab bhi kharij rahe (`REPORT-DEAL-COMPONENTS-1` na toote)
- Details section me sab kuch waise hi rahe
- Fix B ke liye: category rows ke beech paisa hile magar **grand total na hile**
- Guard sabit karna: fix hata kar dekhna ke test laal hota hai

**Regression:** Report, Dashboard, Catering, Shift suites + dono tenants ka
`docs/snapshots/report-sections-before-2026-09-01.txt` se **diff** — Fix A par sirf ITEM rows
badalni chahiye, aur kisi line me farq nahi aana chahiye.

## 8. Asal jar (alag kaam)

Ye sab isi liye hai ke **deals ka apna product nahi hai**. Agar har deal apna product rakhta (ya
`combos` ko report me first-class maana jata) to na naam mixta, na category ghalat hoti. Wo bara
kaam hai; upar wale do fix us ke baghair bhi masla hal kar dete hain.
