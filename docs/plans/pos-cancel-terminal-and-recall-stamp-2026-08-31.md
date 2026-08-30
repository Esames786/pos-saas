# POS-CANCEL-TERMINAL-1 — cancel karne wale ke counter par parchi, aur recall par terminal ka re-stamp

**Status:** DESIGN / PLAN — confirm karein, phir build → test → deploy
**Date:** 2026-08-31
**Author:** Claude
**For:** Kashif Food (LIVE). Khatri Biryani bhi isi code par hai — regression lazmi.
**Related:** `940b1ce` RECALL-REPRINT-TERMINAL-1 · `f0923e4` RECALL-REPRINT-TERMINAL-2 (local, undeployed)

---

## 1. Owner ki demand

> "Jo bhi order recall kare — **chahe save na bhi kare** — aur poora order cancel kar de, to KOT
> aur reminders **usi ke paas** aayen."

Aur ek sawal:

> "Save karne par order us ke terminal par attach kyun ho raha hai? Ye kaam kis ne karwaya tha,
> aur agar karwaya tha to kyun?"

---

## 2. Aaj production me kya ho raha hai (30 Aug ke asal records)

Production abhi `dff1313` par hai — yani RECALL-REPRINT-TERMINAL-2 **wapas liya hua**, purana behaviour.

Terminals: `T1 = Delivery` · `T2 = DTQ 1` · `T3 = DTQ 2` · `T4 = DTQ Floor`.
Printers: `T1 Counter .100` · `T2 Counter .101` · `T3 Counter .102` · `T4 Counter .103`.

### 2.1 ITEM-WISE cancel — cancel karne wale ke counter par (ittefaqan theek)

```
BILL HS-20260830165445-447    order punch hua tha:  DTQ 2
cancel kiya:  Counter T2  (uska apna terminal: DTQ 1)      20:23

CANCEL KOT  ->  T2 Counter Printer     <- cancel karne wale ke saamne
REMINDER    ->  T2 Counter Printer
REMINDER    ->  T3 Counter Printer
REMINDER    ->  T4 Counter Printer     <- TEEN parchi
```

### 2.2 POORA ORDER cancel — order wale counter par (ghalat)

```
BILL HS-20260830175536-994    order punch hua tha:  DTQ 2
cancel kiya:  Counter T2  (uska apna terminal: DTQ 1)      20:44

CANCEL KOT  ->  T3 Counter Printer  (Chicken Biryani)
CANCEL KOT  ->  BBQ / Grill KOT     (Chicken Roll)
CANCEL KOT  ->  T3 Counter Printer  (Beverages)
REMINDER    ->  T3 Counter Printer
```

Cancel karne wala **DTQ 1** par khara tha. Uske saamne **kuch nahi nikla**.

### 2.3 Aur misaalein

```
HS-20260830145408-688   punch DTQ 2 -> cancel by T2 (DTQ 1) -> parchi T3 Counter   GHALAT
HS-20260830160836-845   punch DTQ 1 -> cancel by T2 (DTQ 1) -> parchi T2 Counter   theek (ittefaq)
HS-20260830181843-989   punch DTQ 1 -> cancel by T2 (DTQ 1) -> parchi T2 Counter   theek (ittefaq)
HS-20260830182403-148   punch DTQ 1 -> cancel by T2 (DTQ 1) -> parchi T2 Counter   theek (ittefaq)
```

Jahan order **usi terminal** ka tha wahan theek laga. Jahan doosre terminal ka tha — ghalat.

---

## 3. Do raaste, do alag natije

| | Cancel KOT | Reminder |
|---|---|---|
| **Item-wise void** | cancel karne wale ka counter ✅ | cancel karne wala **+ har purana counter** (2–3 parchi) ⚠️ |
| **Poora order cancel** | **order wale ka** counter ❌ | **order wale ka** counter ❌ |

### Item-wise theek kyun lagta hai — asli wajah

Item-wise void POS ke **Hold/Save** raaste se guzarta hai, aur wahan
`HeldSaleController::store()` sale ka terminal **dobara stamp** kar deta hai:

```php
$sale->update([
    'branch_id'   => $data['branch_id'],
    'terminal_id' => $data['terminal_id'] ?? null,   // <- yahan sale ka terminal badal jata hai
    ...
]);
```

To T2 ne jab order recall karke save kiya, sale ka `terminal_id` **DTQ 1** ban gaya — aur cancel KOT
wahin chala gaya. **Ye design se theek nahi hua, ittefaq se hua.**

### Poora order cancel ghalat kyun

Wo ek **alag endpoint** (`HeldSaleController::cancel`) hai jo sale ko dobara stamp nahi karta. Us me
`$sale->terminal_id` purana hi rehta hai, aur `KotCancellationService` wahi routing ko deta hai.

### Reminder kai jagah kyun

`queueCancellationReminders()` un tamam printers ko bhi bhejta hai jinhone is order ka **koi bhi
purana reminder** liya tha. Order apni zindagi me kai counters par save hua, is liye teen printers
history me thay — teeno par cancellation ki parchi nikli.

---

## 4. Owner ke sawal ka jawab: ye re-stamp kis ne karwaya tha?

**Kisi ne nahi.** Git history saaf hai:

- Ye line `ffe84fa` (17 May 2026, *"Prompt 9A — restaurant floors tables waiters sessions held sales"*)
  me aayi, jab held sales pehli baar bane.
- Us commit me update block create block ki **naql** hai — wo har posted field wapas likh deta hai,
  aur `terminal_id` un me se ek hai.
- **Kisi commit message me is ka zikr nahi.** Koi prompt, koi plan doc, koi test is behaviour ko
  nahi maangta.

Yani ye **faisla nahi, side effect hai.**

Aur ye aap ke apne `940b1ce` (RECALL-REPRINT-TERMINAL-1) ke **khilaf** hai, jis me saaf likha hai:

> *"the sale row is NEVER re-stamped, so cash/shift/closing stay with the original terminal."*

Us commit ne print routing ke liye ek alag override banaya **taake sale row ko haath na lagana pare**.
Magar Hold ka raasta May se sale row ko re-stamp karta aa raha hai. **Dono ek doosre ko kaat rahe hain.**

### Is re-stamp ka nuqsan

`sales_orders.terminal_id` sirf printing ke liye nahi — us par ye sab chalta hai:

- **Reports** — Report Center ka terminal filter, per-terminal sales
- **Shift / Daily Closing** — kis counter ke khaate me sale gina jaye
- **`UserDataScope::deniesSale()`** — kaun sa operator kaun sa order dekh sakta hai
- **Reprint routing** — `$sale->terminal_id` default hai

To aaj ka haal ye hai: **jis counter ne aakhri baar order save kiya, order usi ke naam lag jata hai**
— chahe order kisi aur counter ne liya ho. T4 ka order T2 ne recall karke save kiya, to wo T2 ki
sales me chala gaya.

---

## 5. Tajweez

### 5.1 Cancel hamesha cancel karne wale ke counter par (owner ki demand)

`f0923e4` (local, undeployed) wala kaam yehi karta hai, magar us me **ek khaali jagah** hai jo ab
bharni hai: wo POS ke `#terminal_id` par chalta hai. Owner ki demand hai **"save na bhi kare"** —
yani `cancel()` endpoint ko bhi operator ka terminal milna chahiye, aur POS us request me `terminal_id`
bhejta hai. Ye pehle se `f0923e4` me shamil hai.

Jo cheez saaf karni hai:

- **Cancel KOT** — sirf cancel karne wale ka counter (+ station printers jaise BBQ/Fastfood, kyunki
  wo kaam wahin ruk raha hai). Station wale rules terminal-agnostic hain, unhein chhedna nahi.
- **Reminder** — **sirf ek**, cancel karne wale ke counter par. Owner ne saaf kaha: double nahi.
  `f0923e4` me `$historicalIds` ka merge terminal diye jane ki soorat me skip hota hai.

### 5.2 Save par sale ka terminal re-stamp — teen options

| | Kya | Asar |
|---|---|---|
| **A** | Hold/Save par `terminal_id` **update na karein** — sirf create par set ho | Order hamesha us counter ka rehta hai jisne liya. Reports/shift durust. `940b1ce` ke usool ke mutabiq. **Meri sifarish.** |
| **B** | Jaisa hai waisa rehne dein | Aakhri save karne wala malik ban jata hai — reports aur shift closing ghalat counter par |
| **C** | Nayi column `last_saved_terminal_id` | Asal terminal mehfooz, aur "kis ne aakhri baar chhua" bhi maloom. Zyada kaam. |

**A ki sifarish kyun:** order ka terminal ek **haqeeqat** hai (kis counter ne order liya), na ke "kis
ne aakhri baar edit kiya". Cash aur shift usi par tikte hain. Print ka masla `940b1ce` ke override se
pehle hi hal ho chuka hai — sale row badalne ki zaroorat hi nahi.

⚠️ **A ka ek asar:** abhi item-wise cancel jo *ittefaqan* theek chal raha hai, wo re-stamp ki wajah se
theek hai. A lagate hi wo tootega — is liye **A akela nahi ja sakta**, 5.1 ke saath hi jayega.

---

## 6. Tarteeb (dono saath, alag nahi)

1. **`f0923e4` deploy karein** — cancel (dono raaste) cancel karne wale ke counter par, reminder sirf ek.
2. **Phir A** — Hold/Save se `terminal_id` ka update hataayein.
3. Dono ke darmiyan deploy na karein: pehla dusre ka sahara hai.

---

## 7. Test plan

- `CancelKotTerminalMySqlTest` (mojood, 6/6) me izafa:
  - **poora order cancel bina save kiye** — T4 ka order, T2 se cancel, parchi **sirf** T2 par
  - purana reminder T4 par mojood ho phir bhi **T4 par doosri parchi na jaye**
  - station printer (BBQ) apna cancel KOT phir bhi le — wo terminal-agnostic hai
- **Naya** `HeldSaleTerminalStampMySqlTest`:
  - T4 ka held order T2 se recall karke save ho → `sales_orders.terminal_id` **T4 hi rahe**
  - us order ki sale T4 ki reports me hi gine
  - `deniesSale` ka behaviour na badle
- **HTTP-level checkout test** — `tenant.pos.store` par asal request. Ye 30 Aug ke incident ka sabaq
  hai: service-level tests us class ke bug ko pakadte hi nahi.
- Regression: printing + POS + scope + DirectPay (aakhri run 292/292).

## 8. Deploy notes

- Koi naya route nahi, koi migration nahi (option A/5.1 me). Option C chunein to additive column.
- Khatri bhi isi code par hai — deploy se pehle uska Σ total unchanged prove karna hai.
- `ClosureCapturesItsVariablesTest` ab har build par chalega (30 Aug ka `$request` wala bug).
