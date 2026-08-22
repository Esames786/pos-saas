# Kashif — Legacy-Software Alignment (design, before any code)

**Date:** 2026-08-22 · **Status:** PROPOSED — awaiting owner "go"
**Tenant:** kashifkitchen only. Khatri is live and is not touched by anything on this page.

Client ke teen matalbe, unke apne screenshots/WhatsApp se:

1. **"Is p gosht nh arha"** — print par har item ke against material nahi dikhta:
   kya material lagega, kitna, aur kaun dega (customer ya hum) — **Urdu + English dono**.
2. **"Her row k cost block ka design old software jaisa"** — Order Rate / Making Chrg /
   Meat Rate / Additional wali patti har estimate row par.
3. **Legacy 18 categories** — "kashif kitchen categories where all products are standing"
   (STARTERS … SOFT DRINKS) mein 888 items khade hon, hamari andaazan categories mein nahi.

Guiding rule (owner ki shart): **current system ko ziyada hilaye baghair** — sirf
presentation aur naming; koi DB schema change nahi, koi service/endpoint change nahi,
koi finance/stock touch nahi.

---

## 1. Print: har row ke neeche material ki tafseel (Urdu + English)

### Aaj kya hota hai
`estimate-body.blade.php` (A4 print, email PDF aur bulk documents — teeno isi ek partial
se bante hain) har line par sirf item ka naam, qty, rate, amount chhapta hai. Customer-
supplied ho to ek chhoti line "(Customer will supply: Beef)" aati hai — bas.

### Kya banega
Har item ke naam ke neeche ek chhota material box, **line ke snapshot** se
(`catering_estimate_line_cost_blocks` — wohi jo quote hua tha, product master kabhi nahi):

```
1   12  KG   Biryani Masala Beef
             ┌ سامان / Materials ──────────────────────────────┐
             │ Beef        6 KG     گاہک دے گا / Customer provides │
             │ Rice        4.8 KG   ہم دیں گے / We provide          │
             │ Masala      0.6 KG   ہم دیں گے / We provide          │
             └─────────────────────────────────────────────────┘
```

- **Quantity = line ka TOTAL kitchen draw** (`event_material_qty`) — 100 KG biryani par
  120 KG gosht wala hi hisaab, per-dish ratio nahi. Yehi number kitchen release sheet
  bhi use karti hai, is liye print aur kitchen kabhi alag nahi bolenge.
- **Kaun dega** har material par likha aayega, dono zabanon mein hamesha
  (customer: **گاہک دے گا / Customer provides**, hum: **ہم دیں گے / We provide**) —
  chahe document English mode mein ho ya Urdu mode mein.
- Sirf **material** blocks chhapenge. Making/packing charges Rate column ka hissa hain;
  aur **hamari internal cost (Costs us) print par kabhi nahi jayegi** — woh margin ka
  raaz hai, customer ka kaghaz nahi.
- Materials na hon (service items, decoration) to box hi nahi aata — kaghaz saaf rehta hai.

### Kya NAHIN badlega
Rate/Amount columns, totals, draft banner, settlement block — sab jaise hain waise.
Sirf item cell ke andar ek naya sub-block. **Files: 1 blade partial.**

---

## 2. Har row ka Cost Details panel — old-software patti

### Aaj kya hota hai
Row kholne par seedha 5-column table aata hai (Part / Customer charge / Kitchen uses /
Costs us / Contribution). Sach poora hai, magar operator ko legacy screen ki
ek-nazar summary nahi milti.

### Kya banega
Table ke **upar** legacy-style boxes ki patti (sirf HTML/CSS, koi naya endpoint nahi):

```
┌─ ORDER RATE ─┐  ┌─ MAKING CHRG ─┐  ┌─ BEEF RATE ─┐  ┌─ ADDITIONAL ─┐
│   3,465.00   │  │    1,200.00   │  │   2,000.00  │  │    265.00    │
│   per KG     │  │               │  │  گاہک دے گا │  │              │
└──────────────┘  └───────────────┘  └─────────────┘  └──────────────┘
```

Mapping (legacy naam → hamara sach, koi naya hisaab nahi — wohi numbers jo table mein hain):

| Legacy box | Hamara number |
|---|---|
| **ORDER RATE** | `calculated_rate` (blocks ka per-unit total) — bold, read-only |
| **MAKING CHRG** | Making block (`charge_role = making`) ka per-unit hissa |
| **{MATERIAL} RATE** | sab se bara material block — box ka label us material ka NAAM hoga (Beef Rate / Flour Rate), fixed "Meat Rate" nahi, kyunke desserts mein meat nahi hota |
| **ADDITIONAL** | baqi sab per-unit blocks ka jor (chhote materials + doosre charges) |
| *(agar ho)* **ONE-TIME** | lump-sum charges alag box — ye kabhi per-unit rate mein nahi milte (grammar ka qanoon) |
| *(agar ho)* **QUOTED** | override hua rate — warning rang, wajah ke saath (pehle se maujood alert bhi rahega) |

- Customer-supplied material ke box par **گاہک دے گا** tag, contribution 0 — teen-number
  farq (Customer charge / Kitchen uses / Costs us) neeche wale table mein poora qaim rehta hai.
- Patti **read-only summary** hai. Editing wohi existing controls se hogi jo neeche table
  mein already hain (`[data-act]` endpoints) — **do editing raste nahi banenge**, warna
  do qanoon ban jate.
- Add aur Edit dono jagah aayegi (dono wohi ek partial `line-cost-details.blade.php`
  use karte hain, is liye khud-ba-khud).

### Kya NAHIN badlega
Koi service, koi route, koi DB column, quoted-rate/override/customer-supplied ki
authorities — sab jahan hain wahan. **Files: 1 blade partial (+ CSS chhota sa).**

---

## 3. Categories: legacy 18 list, jahan sab products khade hain

### Evidence — mapping tukka nahi hai
Legacy sequence number ke **saikron (hundreds) block** hi category code hai:

- Client ke apne screenshot ka saboot: item 361 BIRYANI MASALA BEEF → Sequence **203**,
  Category **2 RICE**. (200s → cat 2 ✓)
- Hamare 888 items ko hundreds-block se baantne par 10 lagatar categories legacy list
  par **exact** baithti hain (samples parh kar tasdeeq shuda): desserts-block →
  DESSERTS, roti/paratha-block → NAN-TANDOOR, raita → RAITA, salad → SALAD,
  chutney → CHATNIES, tea → TEA/COFEE, Pan Stall → PAN, cold drink/mineral water →
  C/D-M/W, chaat/fruit → AFTARI, patties/sandwich → HI-TEA.
- CURRIES bara hai (209 items) is liye do blocks (300s + 400s) leta hai — isi se
  aage ka one-block shift aata hai, jo samples se match karta hai.

### Final mapping (seq-hundreds → legacy category)

| Seq block | Legacy category | | Seq block | Legacy category |
|---|---|---|---|---|
| 0xx, 1xx | 1 STARTERS | | 10xx | 9 RAITA |
| 2xx | 2 RICE | | 11xx | 10 SALAD |
| 3xx, 4xx | 3 CURRIES | | 12xx | 11 CHATNIES |
| 5xx | 4 BBQ | | 13xx | 12 TEA/COFEE |
| 6xx | 5 FRIED | | 14xx | 13 PAN |
| 7xx | 6 SIDE LINES | | 15xx | 14 C/D - M/W |
| 8xx | 7 DESSERTS | | 16xx | 15 AFTARI |
| 9xx | 8 NAN-TANDOOR | | 17xx | 16 HI-TEA |
| 18xx+ (incl. 20xx decoration/packing) | 17 OTHERS | | | |

- Category naam **hu-ba-hu legacy spelling** mein banenge (CHATNIES, TEA/COFEE,
  C/D - M/W) — wohi usool jo 55 kitchen instructions mein chala.
- **SOFT DRINKS (18)** khali banegi (hamare data mein iska block nahi milta; cold
  drinks C/D-M/W block mein hain) — owner jise chahe wahan move kar de.
- Sort order = legacy code order, taake dropdown unke purane software jaisa parhe.

### Hifazat ke qanoon (idempotent, additive)
1. Sirf **KM-*** products move honge, aur sirf tab jab unki mojooda category
   seeder ki apni banayi hui ho (`client-menu-*` slug). **Owner ne jo item khud
   kisi category mein rakha, seeder usay kabhi wapas nahi ghaseetega.**
2. Purani andaazan categories ("Rice & Biryani", "Karahi / Qorma / Handi" waghaira)
   khali hote hi **deactivate** hongi (delete nahi).
3. "Needs Review" jaisi hai waisi rahegi — gandi rows ko plausibly ghalat file nahi kiya jata.
4. Command wohi allowlisted `catering:bootstrap-client-menu kashifkitchen --yes
   --confirm=kashifkitchen`, wohi GL/stock before-after self-check: **paisa aur stock
   zero movement**, warna SAFETY VIOLATION aur fail.

**Files: 1 seeder (KashifClientMenuSeeder) — categoryFor() + ensureCategories().**

---

## Kya cheez is design mein jaan boojh kar NAHIN hai

- **Koi DB migration nahi.** Teeno kaam mojooda columns/snapshots par chalte hain.
- **Koi naya pricing path nahi** — patti sirf dikhaati hai, hisaab wohi block grammar.
- **Print par internal cost kabhi nahi** — sirf material, qty, kaun-dega.
- Purchase→rate sync (client ka point #3) is mein shamil NAHIN — woh alag design hai.
- Complimentary flag / Qty-In-No legacy fields — abhi nahi; zaroorat sabit ho to additive.

## Test + deploy plan (approval ke baad)

1. Guards: estimate print par material rows + provider text (dono zabanein) ka test;
   category re-file ka test (203-wala item RICE mein, owner-moved item apni jagah,
   khali purani category inactive, GL/stock untouched) — `CateringClientMenuBootstrapMySqlTest`
   + operator-UI guard extend.
2. Full MySQL suite → green → scoped commit → `bash deploy.sh` → prod par sirf
   allowlisted bootstrap command (kashifkitchen) → Khatri read-only smoke.

**Estimate: 2 blade partials + 1 seeder + tests. Core services/DB: zero touch.**
