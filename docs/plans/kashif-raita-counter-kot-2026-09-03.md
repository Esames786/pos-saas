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
