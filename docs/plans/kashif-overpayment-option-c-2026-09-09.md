# Option C — invoice se zyada paisa lene ki soch samajh kar ijazat

**Date:** 2026-09-09 · **Tenant:** `kashifkitchen` · **Status:** PLAN — koi code nahi laga
**Base:** `docs/plans/kashif-overpayment-2026-09-09.md` (wahan teen raaste hain; ye us ka C hai)

Owner ne C chuna: extra raqam **amanat (customer credit)** ban kar 2300 par baithe, aamdani
na bane, aur baad me refund ho.

---

## 1. Faisla jo sab se pehle likhna zaroori hai

Ye **hisab-kitab ka mauqif** badal raha hai, feature nahi.

Aaj system kehta hai: *"business wo paisa na rakhe jiska us ne bill nahi kiya."*
C ke baad wo kahega: *"rakh sakta hai — magar sirf jab operator jaan boojh kar kahe, aur wo
paisa **qarz** ki tarah dikhe, aamdani ki tarah nahi."*

Ye jaiz mauqif hai. **Sharat ek hai:** extra raqam kabhi bhi, kisi report me, **aamdani** ke
saath na gine jaye. Ye poore plan ki bunyaad hai.

---

## 2. Bunyaad pehle se maujood hai — jitni maine socha thi us se zyada

| cheez | haalat |
|---|---|
| `2300 Customer Advances` (liability) | **hai** |
| `customer_credit` ka hisab | **hai** — `max(net_received − billed, 0)` |
| `refundable = customer_credit` | **hai** |
| Refund ka poora raasta (`Dr 2300 / Cr cash-bank`) | **hai**, aur cash/bank ka naam lazmi |
| Invoice sirf apni qeemat jazb karti hai | **hai** — `advance_applied = min(advance_total, grand_total)`, baqi 2300 par |
| Booking statement par credit dikhna | **hai** |
| Credit hote hue event band na hona | **hai** — *"refund the credit before closing"* |

Yaani **niche ka poora dhaancha tayyar hai.** C ka kaam sirf ek darwaza kholna hai — aur ek
aisi cheez theek karni hai jo abhi tak zarurat na hone ki wajah se saamne nahi aayi.

---

## 3. Asal masla: invoice ke BAAD zyada paisa lena

Ye plan ka sab se ahem hissa hai aur aasani se nazar-andaz ho sakta hai.

Aaj receipt ka head **ek hi** hota hai, aur wo is satar se tay hota hai:

```php
$isSettlement = $event->finalInvoice()->exists();
// true  → Cr 1300 Accounts Receivable
// false → Cr 2300 Customer Advances
```

Ab farz karein invoice **10,000** ki hai, kuch nahi mila, aur customer **20,000** deta hai.
Invoice mojood hai → poori 20,000 **Cr 1300** ho jayegi → **Accounts Receivable manfi 10,000**.

**Ye ghalat khaata hai.** AR ka matlab hai "customer par hamara udhaar"; manfi AR ka koi
matlab nahi. Wo 10,000 **2300** par jani chahiye, kyunki wo hum par customer ka qarz hai.

**Is liye ek receipt ko BAANTNA parega:**

```
Dr  Cash/Bank            20,000
    Cr  1300 AR                    10,000   ← jitna bill baqi tha
    Cr  2300 Customer Advances     10,000   ← extra, amanat
```

### Do tareeqe, aur main kaun sa tajweez karta hoon

**(a) Do rows** — ek `settlement`, ek `advance`.
*Faida:* har row ka apna saaf head; refund ka mantiq bilkul na chhue.
*Nuqsan:* operator ne **ek** payment li thi; statement par do satarein uljhan degi, aur har
jagah "ye dono asal me ek hi hain" samjhana parega.

**(b) Ek row, ek journal jis ki teen lines hon** — **ye meri tajweez hai.**
*Faida:* ek payment = ek row = ek journal. Ye ghar ka apna usool hai (*"an invoice exists iff
its GL exists"*). `position()` `advances.amount` jama karta hai — wo waise hi durust rahega.
Replay guard `total_debit` par hai (20,000) — wo bhi theek.
*Nuqsan:* `posting_type` ab poori row ka jawab nahi rahega; ek naya khana chahiye — `credit_portion`
— taake baad me bhi pata rahe ke us receipt ka kitna hissa amanat tha.

**Invoice se pehle koi taqseem nahi chahiye** — wahan sab kuch pehle hi 2300 par jata hai.

---

## 4. Kya kya badlega

| # | jagah | tabdeeli |
|---|---|---|
| 1 | `CateringAdvance::creating` guard | inkar **default rahega**. Sirf tab khulega jab receipt par `allow_overpayment = true` **aur** `overpayment_reason` likha ho |
| 2 | migration (additive) | `catering_advances` par `credit_portion` (decimal, default 0) aur `overpayment_reason` (nullable string) |
| 3 | `CateringAdvanceService::record` | balance aur extra ka hisab; extra ho to teen-line wali posting |
| 4 | `JournalPostingService` | naya `postCateringSplitReceipt()` — `Dr cash / Cr 1300 (balance) / Cr 2300 (extra)` |
| 5 | Advance ka form | ek checkbox *"Invoice se zyada le rahe hain"* + **wajah lazmi**; checkbox lagate hi saaf likha aaye ke extra **amanat** hai, aamdani nahi |
| 6 | Booking statement | receipt ke neeche ek satar: *"is me se X amanat hai"* |
| 7 | Permission | ye ek naya **ikhtiyar** hai (`tenant.catering.advances.overpay`) — **har role par alag se dena parega**, `deploy.sh` sirf Owner ko deta hai |

**Jo NAHI badlega:** refund, closure guard, invoice ka `advance_applied`, `position()` ka
hisab. Sab pehle se durust hain.

---

## 4b. Usi screen se paisa wapas — `-10,000` wali entry

Owner: *"ye bhi ho sakta hai ke main 20k loon aur phir kuch din baad **usi screen se
−10,000** ki entry kar doon."*

Ye durust maang hai aur is ka jawab **sirf ek darwaza** hai, do nahi. Magar us ke peeche jo
hota hai wo **wohi purana Refund** rahega — kyunki paisa bahar jane ka khaata pehle se
maujood aur durust hai.

### Kaise

Receipt ke khane me manfi raqam qubool hogi, aur wo **`CateringRefund` bana degi** — nayi
qism ka koi record nahi.

```
Operator likhta hai:  -10,000
System banata hai:    CateringRefund 10,000
GL:                   Dr 2300 Customer Advances / Cr Cash-Bank
```

### Manfi `CateringAdvance` kyun NAHI

Ye pehla khayal aata hai aur ghalat hai. `CateringAdvance` ki manfi row:

- `position()` ko torti hai — wo `advances.amount` **jama** karta hai, aur `gross_received`
  ka matlab hi khatam ho jata
- refund ki hadd (`refundable`) se bach nikalti hai, yaani us paise ko bhi bahar bhej sakti
  hai jo kisi bill par laga hua hai — aur balance due dobara zinda ho jata
- replay guard `total_debit` par hai; manfi raqam us ka matlab badal deti hai

**Ek screen, wohi khaata** — yehi sahi shakl hai.

### Do sharatein jo waise hi qayam rahengi

1. **Wajah lazmi.** Refund me `reason` pehle se lazmi hai. Manfi entry par bhi wahi poocha
   jayega.
2. **Cash/bank ka naam lazmi.** Code ka apna jumla: *"Money out must name the account it left
   from"* — bina mapped account ke posting inkar karti hai.

### Hadd — aur yehi is ka asal tahaffuz

Wapas sirf **wo paisa** ja sakta hai jo kisi bill par laga hua **nahi** hai:

```
refundable = customer_credit = max(net_received − billed, 0)
```

| soorat | natija |
|---|---|
| Invoice 10,000 · liya 20,000 · entry −10,000 | **chalega** — credit theek 10,000 hai |
| Wohi soorat · entry −15,000 | **inkar** — 5,000 us bill ka hai; wapas bhejne se balance due dobara khara ho jata |
| Invoice 10,000 · liya 10,000 · entry −5,000 | **inkar** — koi credit hai hi nahi |

Yaani manfi entry **amanat wapas karti hai, bill nahi torti.**

### Statement par kaisa dikhega

`Booking Statement` me pehle se `Money out` ka khana maujood hai. Ye entry wahin baithegi —
`Refund` ke naam se, apni wajah ke saath — aur us ke baad `customer_credit` sifar par aa
jayega, jis ke baad **event band ho sakega** (closure guard credit hote hue rokta hai).

---
## 5. Khatre — aur har ek ka jawab

| khatra | jawab |
|---|---|
| **Extra raqam kabhi aamdani me gin li jaye** | Wo `4160` ko chhuti hi nahi. Guard: over-payment ke baad `4160` ka total **bilkul waisa hi** ho jaisa us se pehle tha |
| **AR manfi ho jaye** | Yehi wajah hai ke receipt baanti ja rahi hi. Guard: over-payment ke baad `1300` ka balance **kabhi manfi na ho** |
| **Darwaza khul kar khula reh jaye** | Ijazat **har receipt par alag**, checkbox + wajah ke saath. Koi tenant-level setting **nahi** — wo chup chaap qaida band kar deti |
| **Amanat bhool jaye** | Closure guard pehle se rokta hai: credit hote hue event band nahi hota. C is ko **nahi chhuta** |
| **Operator ko lage paisa "mil gaya"** | Screen par lafz `Customer credit held` — "extra received" nahi. Ye qarz hai |
| **Purani rows par asar** | `credit_portion` default 0 — har purani receipt ka matlab bilkul wahi rehta hai |

---

## 6. Guards (har fix ek bar hata kar RED dekha jayega)

1. Bina ijazat over-payment **ab bhi mana** — aur paigham wahi
2. Ijazat ke saath: receipt bani, `credit_portion` durust, journal ki **teen lines**
3. `4160` (revenue) over-payment se **hilta nahi**
4. `1300` (AR) **kabhi manfi nahi**
5. `2300` par jitna extra tha, utna hi khara hai
6. `position()`: `customer_credit` = extra, `balance_due` = 0
7. Refund us extra ko utha leta hai aur 2300 sifar par aa jata hai
8. Credit hote hue **event band nahi hota**
9. Bina wajah likhe ijazat **kaam nahi karti**
10. Purani receipts (jinka `credit_portion` 0 hai) bilkul waise hi post hoti hain
11. Manfi entry **Refund banati hai**, manfi advance **nahi**
12. Manfi entry `refundable` se zyada par **inkar** karti hai, aur balance due dobara khara nahi hota
13. Manfi entry bina wajah aur bina cash/bank account ke **nahi** chalti

---

## 7. Tarteeb

| # | qadam | kyun is tarteeb me |
|---|---|---|
| 1 | Migration (additive) — dono naye khane | koi behaviour nahi badalta |
| 2 | `postCateringSplitReceipt()` + uske guards | posting pehle, screen baad me |
| 3 | Guard ka darwaza + `record()` ki taqseem | ab qaida badla, magar UI abhi nahi |
| 4 | Form ka checkbox + wajah + permission | ⚠️ naya permission = har role par alag se dena + `system:clear-tenant-permission-cache` |
| 5 | Statement par "is me se X amanat" | |
| 6 | Usi khane me manfi raqam → Refund (§4b) | posting ka kaam pehle ho chuka hoga; ye sirf ek raasta hai |

**Qadam 1–3 ke baad ek deploy**, aur us par ek din — kyunki us waqt tak koi bhi over-payment
kar hi nahi sakta (UI nahi hai), yaani deploy be-khatar hai.

---

## 8. Ek baat jo tajweez badal sakti hai

Live par abhi tak **koi refund, koi customer credit, koi journal entry nahi** — `journal_entries = 0`.
Yaani **ye soorat aaj tak ek bar bhi pesh nahi aayi.**

Is liye meri raye: **qadam 1–3 bana lein** (wo chhupe hue hain aur kuch torte nahi), magar
**qadam 4 ka UI tab kholen jab pehli dafa waqai zarurat pesh aaye.** Jis darwaze ki abhi
zarurat nahi, us ka khula rehna khud ek khatra hai.

Aur ek sawal jis ka jawab owner ke paas hai, mere paas nahi: **agar kabhi tax laga**, to
amanat par tax nahi banta aur aamdani par banta hai. Kashif Kitchen par tax abhi 0 hai, magar
raasta A (quotation barhana) aur raasta C (amanat) ka asal farq yehi hai.
