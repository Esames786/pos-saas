# Kashif Food — Singaporean Rice Khass ko Platters se nikaal kar apne head me (research)

**Tareekh:** 2026-09-05 · **Tenant:** kashiffood (#348)
**Halat:** RESEARCH — prod par kuch nahi badla. Sab read-only.

---

## 1. Kya maanga gaya

> "Ye Platter me se Singaporean Rice Khaas ko hata ke usko alag Singaporean Platter me daal do
> taake hisab theek aaye."

---

## 2. Deal ka "head" aata kahan se hai

Report ka faisla **ek line** par hai:

```php
SalesReportEngine::lineCategoryExpr()  →  COALESCE(cb.category_id, p.category_id)
```

Yani jis line par `combo_id` ho, uski category **combo ki apni** hoti hai (`combos.category_id`),
product ki nahi. `byDeal()` isi se `MAX(c.name) as head` uthata hai.

> ⚠️ Meri purani yaadasht me likha tha ke `combos.category_id` **display-only** hai (POS ki pills
> ke liye) aur report `product.category_id` par chalti hai. **Ye ab durust nahi.** POS-COMBO-
> CATEGORY-1 ke waqt aisa tha; baad me DEAL-CATEGORY-1 ne report ko bhi combo ki category par
> laga diya. Is liye ye badlav **report par asar karega** — aur yehi maqsad hai.

## 3. Aaj ki haalat

**Deals (#31)** ke bachche:

| Category | combos |
|---|---:|
| #33 Midnight | 32 |
| **#34 Platters** | **9** |
| #43 Chinese Deals | 1 |
| #35 Family Deal | 4 |
| #37 Exclusive Deals | 1 |
| #39 Meal Deal | 2 |
| #38 Pocket Friendly | 4 |
| #32 Al-Faham *(band)* | 2 |

Hilne wala **sirf ek combo**: `#6 Singaporean Rice Khass (2-3 Persons)`, abhi `#34 Platters` me.

Us ki ab tak ki bikri: **175 qty · 101,500.00** (31 Aug se 4 Sep, 160 lines).

| | qty | raqam |
|---|---:|---:|
| Platters ka kul (ab tak) | 613 | 259,100.00 |
| isme combo #6 ka hissa | 175 | 101,500.00 |
| Platters me bachega | 438 | 157,600.00 |

> Isi liye owner ko "hisab theek nahi lag raha" — Platters ke 259,100 me se **39%** akela ye ek
> deal hai.

## 4. Kya kya BADLEGA

1. **DEALS section** — `Singaporean Platter` ka apna head, usme ye ek deal
2. **CATEGORIES section** — `Deals` ke neeche naya sub-head; `Platters` 101,500 halka
3. **ITEMS BY CATEGORY** — wahi sub-head badlega
4. **POS** — `Deals` tab me ek nayi pill, usme ye ek deal. Cashiers ko **Ctrl+F5**

⚠️ **Purani reports bhi badlengi.** Report live join karti hai (`combos.category_id`), snapshot
nahi rakhti — is liye 31 Aug se ab tak ka 101,500 bhi naye head me chala jayega. Agar aap 3 ya 4
September ka report dobara nikalen to Platters kam dikhega. **Ye maqsad ke ain mutabiq hai**, magar
pehle se jaan lena zaroori hai.

## 5. Kya kuch bhi NAHI badlega

| Cheez | Kyun |
|---|---|
| **Net sale — ek rupya nahi** | Nayi category `Deals` (#31) ki **child** hai, is liye root wahi rehta hai. Report root par jamti hai; `Deals` ka kul, aur poore din ka NET SALES, dono jyun ke tyun |
| **KOT routing** | Routing har line ki **product** category par hai, aur `combo_header` chhod diya jaata hai. Is combo ke chaaron components (`Rice of Khaas`, `Chicken Baluchi Boti`, `Chicken Shahi Chattakh`, `Chicken Boti Boneless`) **Bar-B-Que** me hain aur BBQ KOT par hi jayenge — inhein chhua hi nahi ja raha |
| **Qeemat / bill / receipt** | Combo ka `price` aur uske components achhoot |
| **Purane bills aur chhap chuki parchiyan** | Un me kuch nahi badalta |
| **Baqi 8 Platters combos** | Apni jagah |
| **Khatri Biryani** | Bilkul achhoot |

## 6. Kaam kitna hai

**Do khaane, koi code nahi, koi deploy nahi:**

1. Nayi category `Singaporean Platter` — `parent_id = 31` (Deals), `sort_order = 3`
   (Platters ke foran baad; baqi ka sort ek qadam aage khiskega ya nahi, §7 dekhein)
2. `combos.category_id` — combo **#6**: `34 → nayi id`

## 7. Faisla jo owner ka hai

- **Naam:** `Singaporean Platter` — ya kuch aur? Ye POS ki pill par aur report ke head par
  chhapega.
- **Tarteeb:** Platters ke foran baad (sort 3) rakhun, ya list ke aakhir me? Report ab menu ki
  tarteeb follow karti hai (REPORT-CATEGORY-ORDER-1), is liye jagah nazar aayegi.

## 8. Tasdeeq (badlav ke baad)

1. `Deals` root ka kul — **badlav se pehle aur baad barabar**
2. Poore din ka NET SALES — **barabar**
3. Platters ab 157,600, naya head 101,500 — **jama 259,100**
4. Combo #6 ka KOT dry-run — **BBQ / Grill** hi aana chahiye (chaaron terminals par)
5. POS payload me nayi pill aur usme yehi ek deal

## 9. Wapsi

`combos.category_id` wapas 34, aur nayi category delete. Do khaane. Kuch aur chhua hi nahi jata.
