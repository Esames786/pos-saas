# Khatri Biryani — Extras aur Beverages ke child categories (research)

**Tareekh:** 2026-09-05 · **Tenant:** khatribiryani · **Halat:** RESEARCH — prod par kuch nahi badla.

---

## 1. Kya maanga gaya

> Extras ke neeche do child: **Boxes and Sauces** (Singaporean sauce yahan) aur **Raitas** (Raita
> yahan). Beverages ke neeche do child: **Mineral Water** aur **Soft Drinks**.

## 2. Aaj kya hai

### Extras (#8) — 3 products

| Product | Qeemat | 30 din ki bikri |
|---|---:|---:|
| #39 Raita | 70 | **1,508 qty · 105,560** |
| #41 750 ML Box | 30 | 18 qty · 540 |
| #42 1500 ML Box | 50 | 3 qty · 150 |

### Beverages (#7) — 8 products

| Product | Qeemat | 30 din ki bikri |
|---|---:|---:|
| #33 Cola Next 300 ml | 90 | **2,845 qty · 256,050** |
| #31 Mineral Water (Small) | 60 | 693 qty · 41,580 |
| #34 Cola Next 500 ml | 110 | 276 qty · 30,360 |
| #37 1 Ltr Coldrink | 160 | 165 qty · 26,400 |
| #32 Mineral Water (Large) | 120 | 207 qty · 24,840 |
| #38 Pakola 300 ml | 90 | 208 qty · 18,720 |
| #35 Cola Next 1.5 Ltr | 180 | 81 qty · 14,580 |
| #36 Coldrink Jumbo | 240 | 27 qty · 6,480 |

Taqseem saaf hai: **2 mineral water**, **6 soft drinks**.

## 3. ⚠️ Sab se ahem baat — routing

`PrintRoutingService::mappedPrinterIds()` line ke product ki **apni** `category_id` par match karta
hai. **Parent tak nahi chadhta.**

Yani nayi child category banate hi, agar uske apne printer mappings na hon, to us ke products ka
KOT `defaultKotPrinter()` par gir jayega — pehle terminal ki setting, phir branch ka pehla active
KOT printer. **Ye ghalat printer ho sakta hai, aur kisi ko pata bhi nahi chalega.**

Abhi dono parents ka routing:

| Category | KOT |
|---|---|
| #8 Extras | 4 rows → `XPrinter - Beverages / Desserts / Extras KOT`, har terminal pinned |
| #7 Beverages | 4 rows → wahi XPrinter, har terminal pinned |

**Is liye har nayi child ko apne 4 `kot` rows chahiyen — apne parent se hoobahoo nakal.** (Khatri
par in categories ke `reminder` rows hain hi nahi, sirf `kot` — to 4 hi banenge.)

Ye wohi trap hai jo Kashif me pakda gaya tha: `Extras` ke counter rows band the aur "Extras me daal
do" wala aasan raasta product ko Fastfood bhej deta.

## 4. Kaam ki fehrist

| # | Kya | Rows |
|---|---|---:|
| 1 | 4 nayi categories: `Boxes and Sauces`, `Raitas` (parent 8); `Mineral Water`, `Soft Drinks` (parent 7) | 4 |
| 2 | **16 mapping rows** — har child ke 4 `kot` rows, apne parent se nakal | 16 |
| 3 | `products.category_id`: Raita → Raitas; dono Box → Boxes and Sauces | 3 |
| 4 | `products.category_id`: 2 mineral water → Mineral Water; 6 cold drinks → Soft Drinks | 8 |

## 5. ⚠️ "Singaporean sauce" — ye Khatri me hai hi nahi

Aap ne likha *"Singaporean sauce will go here"*, magar **Khatri me is naam ka koi product nahi**.
Jo sab se qareeb hai:

> **#21 `Extra Sauce`** — 130 rupay, abhi `Singaporean Rice` (#4) category me, 30 din me 10 qty ·
> 1,300

(«Singaporean Sauce» **Kashif Food** me hai — do alag tenants hain.)

**Faisla aap ka:** kya `Extra Sauce` ko `Boxes and Sauces` me le aayein? Agar haan to dhyan rahe ke
wo abhi `Singaporean Rice` me hai — hatane se us category ka total 1,300 kam ho jayega aur ye
`Extras` ke sar par chala jayega. Ye **NET SALES nahi hilata**, magar do categories ke darmiyan
paisa manteqil hota hai. Ya phir naya product banana ho to naam aur qeemat batayein.

## 6. Kya NAHI badlega

- **NET SALES — ek rupya nahi.** Nayi categories `Extras`/`Beverages` ki **children** hain, aur
  report **root** par jamti hai. Dono roots ke total jyun ke tyun
- KOT ka rasta — bashart-e-ke mappings nakal ho jayen (§3). Sab kuch usi XPrinter par
- Qeematein, bills, purani parchiyan, Kashif Food — sab achhoot

## 7. Jo NAZAR aayega

- **POS:** `Extras` tab ke neeche do nayi pills, `Beverages` ke neeche do. Cashiers **Ctrl+F5**
- **Report:** `Extras` aur `Beverages` ke neeche sub-heads. Root ke total wahi
- ⚠️ **Purani reports bhi isi shakl me aayengi** — report live join karti hai, snapshot nahi. 30 din
  ka 105,560 (Raita) `Extras → Raitas` me chala jayega

## 8. Client ki tarteeb se rabt

WhatsApp wali list me `Cold drink`, `Mineral water`, `Raita`, `Extras` alag alag hain — yani client
inhi ko alag dekhna chahta hai. Ye kaam theek us ka jawab hai. Report menu ki tarteeb follow karti
hai (REPORT-CATEGORY-ORDER-1), is liye `sort_order` bhi client ki list ke mutabiq rakhna behtar hoga.

## 9. Tasdeeq (badlav ke baad)

1. `Extras` aur `Beverages` ke **root total** — badlav se pehle aur baad **barabar**
2. Poore din ka **NET SALES — barabar**
3. Har nayi child par KOT dry-run, chaaron terminals: **XPrinter** hi aana chahiye
4. Ek control bhi: `Desserts` (jo chhua hi nahi) bhi XPrinter par hi rehna chahiye
5. POS payload me chaaron nayi pills aur un ke andar sahi products

## 10. Wapsi

`products.category_id` wapas purani, 16 mapping rows delete, 4 categories delete. Sab reversible.

---

# 11. OWNER KA FAISLA (5 Sep) — tasadum hal ho gaya

> "Singaporean Rice se Extra Sauce nikaal kar Extras ki child category **Sauce** me daal do, aur
> Sauce ko waise hi route kar do jaise Singaporean Rice ka station route hai."

Yani `Boxes and Sauces` **ek category nahi** rahegi — sauce ko apni category milegi apni routing ke
saath. Ye theek wohi hal hai jo §10 me pehla raasta tha, aur isi se dono baatein poori hoti hain.

## Ab ka poora naqsha

| Category | Products | KOT kahan | Mapping kis se nakal |
|---|---|---|---|
| **Extras** (#8) | — | — | (parent, khali) |
| ↳ **Raitas** *(nayi)* | Raita | `XPrinter` | **Extras** (#8) |
| ↳ **Sauce** *(nayi)* | **Extra Sauce** | **har terminal ka apna counter printer** | **Singaporean Rice** (#4) |
| ↳ **Boxes** *(nayi — §12 dekhein)* | 750 ML Box · 1500 ML Box | `XPrinter` | **Extras** (#8) |
| **Beverages** (#7) | — | — | (parent, khali) |
| ↳ **Mineral Water** *(nayi)* | Mineral Water Small · Large | `XPrinter` | **Beverages** (#7) |
| ↳ **Soft Drinks** *(nayi)* | Cola Next ×3 · 1 Ltr Coldrink · Coldrink Jumbo · Pakola | `XPrinter` | **Beverages** (#7) |

**`Sauce` ki routing (Singaporean Rice se hoobahoo):**

| Terminal | Printer |
|---|---|
| Delivery | `BlackCopper BC97AC - Delivery Receipt + KOT` |
| Takeaway | `BlackCopper - Takeaway Receipt + KOT` |
| Dine In | `BlackCopper - Dine In Receipt + KOT` |
| Quick Sale | `BlackCopper - QucikSale Receipt + KOT` |

> Yani Extra Sauce ka KOT **abhi jahan nikalta hai wahin nikalta rahega** — sirf uska category head
> badlega. Ye is faisle ki sab se achhi baat hai: kitchen ke liye kuch nahi badalta, sirf report
> aur POS ki tarteeb saaf hoti hai.

## Kaam ki nayi fehrist

| # | Kya | Rows |
|---|---|---:|
| 1 | 5 nayi categories: `Raitas`, `Sauce`, `Boxes` (parent 8); `Mineral Water`, `Soft Drinks` (parent 7) | 5 |
| 2 | **20 mapping rows** — har child ke 4 `kot` rows; `Sauce` ke **cat#4 se**, baqi charon ke apne parent se | 20 |
| 3 | `products.category_id` — Raita → Raitas; 2 boxes → Boxes; **Extra Sauce → Sauce** | 4 |
| 4 | `products.category_id` — 2 water → Mineral Water; 6 drinks → Soft Drinks | 8 |

## 12. Ek chhoti si baat jo abhi tay honi hai

Owner ne `Sauce` ka naam liya, `Boxes` ka nahi. Do surtein hain:

- **(a)** `Boxes` bhi apni child ban jaye *(upar ke naqshe me yehi maana gaya hai)* — phir `Extras`
  poori tarah sirf ek parent reh jata hai, aur report me har cheez apne naam se aati hai.
- **(b)** Dono box `Extras` me hi pare rahen — kaam ek category aur 4 rows kam, magar `Extras` ka
  sar aadha khali aur aadha bhara rahega (kuch products seedhe us par, kuch children me).

**Mera mashwara (a)** — kyunke report me ek hi head ka aadha bhara hona wohi uljhan paida karta hai
jo abhi Platters me thi. Magar ye owner ka faisla hai; boxes ka **routing dono surton me wahi
XPrinter** rehta hai, is liye kitchen par koi asar nahi.

## 13. Paisa — kahan se kahan

Koi rupya banta ya gum nahi hota; sirf **category heads** ke darmiyan manteqil hota hai (30 din):

| Se | Ko | Raqam |
|---|---|---:|
| `Singaporean Rice` | `Extras → Sauce` | **1,300** |
| `Extras` (seedha) | `Extras → Raitas` | 105,560 |
| `Extras` (seedha) | `Extras → Boxes` | 690 |
| `Beverages` (seedha) | `Beverages → Mineral Water` | 66,420 |
| `Beverages` (seedha) | `Beverages → Soft Drinks` | 352,590 |

Sirf pehli qatar **root** badalti hai (Singaporean Rice → Extras); baqi sab apne hi root ke andar
hilti hain. **NET SALES phir bhi ek rupya nahi hilta.**
