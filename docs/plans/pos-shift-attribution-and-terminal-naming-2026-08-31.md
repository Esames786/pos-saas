# POS-SHIFT-ATTRIBUTION-1 — Floor ki shift par cash kyun khada hua, aur terminal/user naming

**Status:** DIAGNOSIS + PLAN — faisla darkaar, phir build → test → deploy
**Date:** 2026-08-31
**Author:** Claude
**For:** Kashif Food (LIVE). Khatri Biryani bhi isi code par — regression lazmi.
**Related:** `22ad93e` POS-CANCEL-TERMINAL-1 (aaj deploy hua) · `940b1ce` RECALL-REPRINT-TERMINAL-1

---

## MASLA 1 — Terminal ka naam aur user ka naam aapas me nahi milte

### Aaj ka data

| Terminal id | code | **display name** | Us ka user |
|---|---|---|---|
| 1 | `T1` | Delivery | Delivery Desk · delivery_kf2 |
| 2 | `T2` | **DTQ 1** | **Counter T2** |
| 3 | `T3` | **DTQ 2** | **Counter T3** |
| 4 | `T4` | **DTQ Floor** | **Floor T4** |

Yani user ka naam **code** par rakha gaya (`Counter T2`) aur terminal ka naam **display** par (`DTQ 1`).
Shifts screen par dono ek saath aate hain:

```
Terminal: DTQ 1        Opened By: Counter T2
```

Padhne wale ko lagta hai ye do alag counters hain. **Hai ek hi.**

### Kyun hua

Onboarding me terminals ko `T1..T4` codes diye gaye aur naam `Delivery / DTQ 1 / DTQ 2 / DTQ Floor`
rakhe gaye — code aur naam ka number **jaan-boojh kar alag** hai (T2 = DTQ **1**, kyunki T1 Delivery
hai). Users ko code se naam diya gaya. Dono conventions durust hain, magar **saath rakhne par
gumrah karte hain**.

### Options

| | Kya | Asar |
|---|---|---|
| **A** | Users ka naam terminal ke naam par: `Counter T2` → **`DTQ 1 Counter`**, `Counter T3` → `DTQ 2 Counter`, `Floor T4` → `Floor Counter` | Screen par dono ek jaise. Staff jis naam se counter ko jaante hain wahi. **Sirf data.** **Meri sifarish.** |
| **B** | Terminal ka naam code par: `DTQ 1` → `T2` | Staff ka rozmarra ka naam chhin jata hai. Sifarish nahi. |
| **C** | UI me code hamesha naam ke saath: `DTQ 1 (T2)` | Gumrahi khatam, magar har screen par code ka shor. Chhota code change. |

**A ki sifarish kyun:** terminal ka naam wo hai jo staff bolte hain (`DTQ 1`). User uska operator hai,
to uska naam bhi wahi hona chahiye. Login email (`counter2_kf@`) badalne ki zaroorat nahi — sirf
display name.

⚠️ **Dhyan:** user ke naam receipts par `CASHIER:` line me chhapte hain aur cancellation audit me
darj hote hain. Naam badalne se **purane records ka naam nahi badlega** (wo snapshot hai) — sirf
aage ke.

---

## MASLA 2 — DTQ Floor sirf punch karta hai, phir uski shift par Rs 1,19,505 expected cash kyun?

### Owner ka sawal

> "Jab DTQ Floor sirf sale karta hai aur uski saari sales T2 ya T3 close karte hain, to us par
> expected cash kaise khara ho gaya? Purane kaam aur commits ke mutabiq jo terminal bill close karega
> order usi ke terminal par attach hota hai — aaj aisa kyun nahi hua?"

### Jawab: terminal to attach hua — **shift nahi hui**

```
Shift #3 (DTQ Floor)   total_sales = 149,610   expected_cash = 119,505   counted = 98,000   var = −21,505

Us shift par lagi 44 paid sales — wo kis TERMINAL par stamp hain:
    DTQ 1  →  28 orders   Rs 106,765
    DTQ 2  →  16 orders   Rs  42,845

DTQ Floor (terminal 4) par stamp koi paid sale:   ZERO
```

Yani **terminal bilkul theek gaya**. Har bill us counter par stamp hai jisne pay kiya. Floor ke naam
par ek bhi paid sale nahi.

**Magar `shift_id` Floor ki shift par hi raha** — aur `expected_cash` shift par tikta hai, terminal par
nahi. Is liye poora cash Floor ki shift me jama hota raha.

### Ye jaan-boojh kar hai (aur ek asal masla hal karta hai)

`SalesOrderController::store()` — [line 452-455]:

```php
// Add Round / recall-to-pay keeps the ORIGINAL shift + business date. A held
// check opened before midnight stays on its opening business day even when
// paid after midnight (and even from a different shift).
'shift_id'      => $sale->shift_id ?? $shift?->id,
'business_date' => $sale->business_date?->toDateString() ?? $businessDate,
```

Ye rule **business date** ke liye banaya gaya tha: raat 11 baje khula check agar 1 baje pay ho to wo
**usi din** ka rahe, agle din ka na ban jaye. `shift_id` bhi saath rakha gaya kyunki business date
shift se aati hai.

Nateeja: Floor par khula har order, chahe T2 pay kare, **Floor ki shift ka rehta hai**.

### Do zarooratein jo aapas me larr rahi hain

| Zaroorat | Kis ko chahiye |
|---|---|
| Order apne **kholne wale din** par rahe (midnight cross) | Reports, business date |
| Cash us **counter ki draaz** me gine jo paisa le raha hai | Shift close, cash variance |

Abhi pehli zaroorat jeet rahi hai aur doosri haar rahi hai. **Floor ki draaz me kabhi paisa aata hi
nahi** — wo pay hi nahi kar sakta (`tenant.pos.store` nahi hai) — phir bhi uski shift Rs 1,19,505
maangti hai, aur band karte waqt Rs 21,505 kam nikalta hai. Ye **jhoota variance** hai.

### Options

| | Kya | Asar |
|---|---|---|
| **A** | Payment par `shift_id` **paying terminal ki open shift** par le jayen; `business_date` purani hi rahe | Cash sahi draaz me. Business date mehfooz (wo alag column hai). **Meri sifarish.** |
| **B** | Jaisa hai | Floor ki shift har roz jhoota variance dikhayegi; T2/T3 ka expected cash kam |
| **C** | Nayi column `paid_shift_id` — cash us par, reporting purani shift par | Sab se durust, magar shift close, daily closing, reports sab ko us column par le jana parega. Bara kaam. |

**A ki sifarish kyun:** `business_date` sale par apni **alag column** hai — usay shift se lene ki
zaroorat nahi. `$sale->business_date` pehle se mehfooz rakha ja raha hai us line me. To shift ko
paying terminal par le jane se **business date bilkul mehfooz rehti hai**, aur cash us draaz me jata
hai jahan wo waqai hai.

⚠️ **A ka asar aaj ke data par:** aaj ki 44 sales Floor ki shift #3 se nikal kar T2/T3 ki shifts me
jayengi. Wo shifts **band ho chuki hain** — to purana data khud ba khud theek nahi hoga. Agar aap
chahein to aaj ke liye alag se ek durusti chalayi ja sakti hai (Khatri shift #40 ki tarah).

---

## Tarteeb

1. **Masla 1 (naming)** — sirf data, foran ho sakta hai, koi deploy nahi.
2. **Masla 2 (shift)** — code change + test + deploy. Aaj ke band shifts ki durusti alag se.

## Test plan (Masla 2)

- `ShiftAttributionMySqlTest`:
  - Floor par held order khule (Floor ki shift), T2 se pay ho → sale ka `shift_id` = **T2 ki shift**
  - usi sale ka `business_date` **na badle** (midnight cross ka case)
  - Floor ki shift ka `expected_cash` us sale se **na barhe**; T2 ki shift ka barhe
  - Add Round (T2 par recall karke save, phir T2 par pay) purana behaviour rakhe
- Regression: Shift, Daily Closing, Report Center, DirectPay, POS (aakhri run **352/352**).
- **HTTP-level checkout test** — `tenant.pos.store` par asal request (30 Aug ke incident ka sabaq).

## Deploy notes

- Koi migration nahi (option A). Option C chunein to additive nullable column.
- Khatri bhi isi code par — deploy se pehle uska Σ total unchanged prove karna hai.
- Aaj ke band shifts ki durusti **alag script** se, deploy ke baad, alag manzoori par.
