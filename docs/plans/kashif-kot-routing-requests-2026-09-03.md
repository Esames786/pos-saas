# Kashif Food — chaar KOT routing ki farmaishein

**Tareekh:** 2026-09-03
**Halat:** **LAGU HO CHUKA (3 Sep)** — sirf catalogue ka DATA kaam, **koi code nahi, koi deploy nahi**.
Jo asal me kiya gaya wo §11 me hai; §1–§10 wo research hai jis par faisle hue.
**Tenant:** Kashif Food (#348), branch 1
**Sab kuch live prod par naapa gaya (sirf SELECT)**

---

## 1. Chaar farmaishein

1. **Bbq sauce** aur **Salad** → BBQ ke KOT par nikle
2. **Cheese** → Fastfood par nikle
3. Naya item **Sauce PKR 80** (fastfood) → kitchen par bhi jaye
4. **Garlic Rice With Shashlik Bar-B-Que** → garlic rice **kitchen** jaye, chicken bbq **botiyan BBQ counter** jayen

---

## 2. Abhi ye cheezein ja kahan rahi hain

### 2.1 Chhe printers

| # | Naam | Kism | Role | IP |
|---|---|---|---|---|
| 1–4 | T1 / T2 / T3 / T4 Counter Printer | network | both | .100 – .103 |
| 5 | **BBQ / Grill KOT** | network | kot | 192.168.1.87 |
| 6 | **Fastfood KOT** | network | kot | 192.168.1.54 |

### 2.2 Har category ka kitchen station

| Station | Categories |
|---|---|
| **Fastfood KOT** | Starter, Soup, Crispy Fried Chicken, Kids House, Burgers, Smash Burgers, Sandwiches, Fries, Pizza Fries, **Chinese**, Continental (+ Steaks, Pasta) |
| **BBQ / Grill KOT** | Bar-B-Que, Bar-B-Que New Arrivals, Paratha, Rolls (+ saat roll ki chhoti categories), Al-Faham Components |
| **KOI STATION NAHI** — sirf counter | Singaporean Rice, Chicken Biryani, Beverages, **Raita & Salad**, **Extras**, Dessert |

Har category apne chaar counter printers par bhi jaati hai (terminal ke hisab se), lekin **kitchen station sirf upar wali do list me hai**.

### 2.3 Chaaron farmaish ka mojooda pata

| Farmaish | Product | Category | Abhi kahan chhapta hai |
|---|---|---|---|
| Salad | `Fresh Salad` #182 (200) | cat#28 Raita & Salad | **sirf counter — kisi kitchen station par nahi jaata** |
| Bbq sauce | ⚠️ **is naam ka product maujood hi nahi** | — | — |
| Cheese | `Cheese` #186 (100) | cat#29 Extras | **sirf counter** |
| Sauce 80 | ⚠️ `Mayo Sauce` #190 pehle se **80** ka hai | cat#29 Extras | **sirf counter** |
| Garlic Rice + Shashlik | `Garlic Rice With Shashlik Bar-B-Que` #68 (1,650) | cat#10 Chinese | **poora ka poora Fastfood KOT** |

**"Bbq sauce" ke qareeb teen cheezein hain**, teenon Extras me aur teenon sirf counter par: `Extra Sauce` (100), `Mayo Sauce` (80), `Singaporean Sauce` (100). Client se poochna parega ke kaunsi murad hai.

---

## 3. System routing kaise karta hai (ye samajhna zaroori hai)

Routing `category_printer_mappings` se hoti hai. Ek row ke khane:

```
branch_id · terminal_id · category_id · printer_id · print_role · order_type · is_active
```

Teen baatein is se nikalti hain:

1. **Routing ki sab se chhoti ikai CATEGORY hai, product nahi.** Product par routing ka koi khana maujood hi nahi (`products` me sirf `category_id` hai). Yani "sirf Cheese ko Fastfood bhejo" ka koi seedha khana nahi.
2. **Har sale line apne product ki category se raasta pakadti hai.**
3. **Deal ke components alag alag raasta pakadte hain** — `combo_header` ko chhod diya jata hai aur har component apni category par jata hai. **Yehi wo mojooda mechanism hai jo ek bikri ko do stationon par tor deta hai.**

Aur ye farzi baat nahi — Kashif par abhi **24 combos** aise hain jo do ya teen stationon par chhapte hain. Misal:

```
Deal 7 (Serve 2)     → 3 station: counter + Fastfood KOT + BBQ/Grill KOT
Chullu Kebab Beef    → 2 station: counter + BBQ/Grill KOT
Bar-B-Que Platter    → 2 station: BBQ/Grill KOT + counter
```

---

## 4. Chaar raaste, aur har ek ki qeemat

### Raasta A — category par seedha mapping laga do

`Extras` (cat#29) ko Fastfood KOT se joro.

- **Code:** koi nahi · **Report par asar:** koi nahi
- ❌ **Magar:** Extras ke **chaudah** products hain — Butter, Bun, Plain Rice, Sizzling Charge, Coleslaw, Dinner Roll, Extra Skewer… **sab Fastfood jayenge**, sirf Cheese nahi. Yehi haal Raita & Salad ka (Raita bhi BBQ chala jayega).
- **Faisla:** sirf tab theek hai jab poori category ek hi station ki ho.

### Raasta B — chhoti (sub) category bana kar us par mapping — **#1, #2, #3 ke liye mera mashwara**

Mojooda root ke neeche naye bache banayen aur sirf mutalliqa products wahan le jayen:

```
Extras (cat#29)
   └── Fastfood Extras   → Fastfood KOT     ← Cheese, Sauce yahan
Raita & Salad (cat#28)
   └── BBQ Sides         → BBQ / Grill KOT  ← Fresh Salad yahan
```

- **Code:** koi nahi — sirf Catalog screen ka data kaam
- **Report par asar:** **koi nahi.** Categories aur Items-by-Category dono **ROOT** par file karte hain (`rootMap()`), aur root wahi rehta hai — Extras, Raita & Salad. Paisa apni jagah, sar apni jagah.
- **Nazar aane wali ek cheez:** Items by Category me us root ke neeche ek naya chhota sar aa jayega (`Extras → Fastfood Extras`) — bilkul waise jaise abhi `Rolls → Chicken Roll` aata hai.
- ⚠️ POS par us pill ke neeche ek nayi child strip aayegi; **cashier ko Ctrl+F5** karna hoga.

### Raasta C — #4 ke liye: use **combo** bana do

`Garlic Rice With Shashlik Bar-B-Que` ko do component ka combo banayen:

```
Garlic Rice With Shashlik Bar-B-Que  (1,650)
   ├── Garlic Rice        → Chinese ya koi Fastfood category  → Fastfood KOT
   └── Shashlik Bar-B-Que → Bar-B-Que (cat#14)                → BBQ / Grill KOT
```

- **Code:** koi nahi — ye system ka aazmaya hua raasta hai (24 combos abhi isi tarah chal rahe hain)
- **⚠️ Report par asar HAI (paisa nahi, jagah):** wo **ITEMS se nikal kar DEALS section me chala jayega**, aur us ka sar `Chinese` se badal kar us combo ki category ho jayega.
  - 25 Aug – 2 Sep me ye **3 bike, 4,950** ka. Yani itna paisa `Chinese` ke sar se `Deals` ke sar par chala jayega.
  - **Kul jama, net sales, GL, cash — kuchh nahi badlega.** Sirf qatar badlegi.
- ⚠️ POS par wo item ki jagah **deal ki tile** ban jayega.

### Raasta D — naya code: product par apna station (routing override)

`products` me ek nayi nullable `kot_category_id` — routing us se ho, report `category_id` se.

- **Code:** ek migration + `PrintRoutingService` me ek line + Catalog screen par ek dropdown
- **Report par asar:** **bilkul koi nahi** — routing aur reporting hamesha ke liye alag ho jayenge
- ✅ #1, #2, #3 saaf hal ho jate hain **bina jhooti categories banaye**
- ❌ #4 phir bhi hal nahi hota — wo "ek product, **do** station" hai, aur us ke liye product ko do hisson me batna hi parega (yani combo)

---

## 5. Paise, report aur structure par asar — saaf jawab

| Cheez | Asar |
|---|---|
| **Paisa / net sales / GL / cash** | **kisi bhi raaste me sifr.** Routing sirf ye tay karti hai ke parchi kis printer par jaye — `quantity`, `unit_price`, `line_total`, `grand_total`, payments, ledger me se kisi ko chhoo-ti hi nahi |
| **Purani bikri (history)** | **kuchh nahi badalta.** Sale line par product ka naam aur qeemat us waqt ki mehfooz hoti hai |
| **Report ka SAR (head)** | Raasta A aur D me **koi tabdeeli nahi**. Raasta B me bhi **root wohi** rehta hai (sirf ek naya chhota sar nazar aata hai). **Sirf Raasta C** me paisa `Chinese` se `Deals` ke sar par jata hai — 25 Aug–2 Sep ke hisab se **4,950** |
| **Stock / inventory** | Kashif ko inventory nahi di gayi; combo banane se recipe/stock ka koi mamla nahi |
| **Purani parchiyan** | KOT ka snapshot mehfooz hai — purani parchi ka reprint waisa hi rahega |

---

## 6. Mera mashwara

| Farmaish | Raasta | Kyun |
|---|---|---|
| Cheese → Fastfood | **B** (Extras → "Fastfood Extras") ya **D** | ek chhota sar banana zyada saaf hai; ya D se bilkul hi saaf |
| Sauce 80 → kitchen | **B** — usi naye chhote sar me | pehle client se poochein: ye `Mayo Sauce` #190 hi hai ya waqai naya item? |
| Salad → BBQ | **B** (Raita & Salad → "BBQ Sides") | Raita ko saath nahi le jana chahiye |
| Bbq sauce → BBQ | **pehle naam tay karein** | is naam ka product maujood nahi |
| Garlic Rice + Shashlik | **C** (combo) | ek bikri ko do stationon par bhejne ka yehi ek raasta hai jo pehle se chal raha hai |

**Agar aap chahen ke report me bilkul kuchh na hile**, to #4 ke liye ek doosra tareeqa bhi hai: product ko **Bar-B-Que** category me daal dein (poori parchi BBQ jaye) aur garlic rice wala hissa BBQ wale khud kitchen se manga lein. Ye ghalat nahi, bas client ki asal farmaish nahi.

---

## 7. Client se poochne wali cheezein (in ke baghair kaam adhoora rahega)

1. **"Bbq sauce"** se murad kaunsa product hai — `Extra Sauce` (100), `Mayo Sauce` (80), ya `Singaporean Sauce` (100)? Ya waqai naya banana hai?
2. **"Naya item Sauce PKR 80"** — `Mayo Sauce` #190 pehle se 80 ka hai. Wohi hai, ya alag?
3. "Kitchen" se murad **Fastfood KOT** hai na? (system me sirf do station hain: Fastfood aur BBQ/Grill)
4. `Garlic Rice With Shashlik` ko deal banane par wo report me **Items se nikal kar Deals** me chala jayega — malik ko manzoor hai?

---

## 8. Kaam ki noiyat aur khatra

- **Raasta A / B / C** — **koi code nahi**, sirf Catalog screen ka data kaam. Deploy ki zaroorat hi nahi.
- **Raasta D** — ek additive migration + routing me ek line. Khatra kam, magar deploy chahiye.
- Har surat me **counter par pehle test** — ek bill punch kar ke dekhna ke parchi sahi station par nikli.
- Cashier ko **Ctrl+F5** (nayi category POS me tab hi dikhegi).

Jawab milte hi jo raasta aap chunen, us par kaam shuru kar dunga.

---

## 9. Poori list — jin ka KOT kisi kitchen station par NAHI jaata

Naapa gaya prod par, bikri **25 Aug – 2 Sep**. Counter printer (T1–T4) bill chhapta hai; **station** wo hai jo kitchen ko pakane ka kehta hai. Ye 42 products sirf counter tak jaate hain.

**Kul: 42 products · 3,109 bike · 1,493,040.00 ka — bina kisi kitchen parchi ke.**

### cat#25 Singaporean Rice — 🔴 sab se bada masla

| id | Product | Rate | Qty | Value |
|---|---|---:|---:|---:|
| #158 | Singaporean Rice (Regular) | 600 | 986 | 613,170 |
| #159 | Singaporean Rice (Large) | 1,050 | 216 | 234,440 |
| #160 | Singaporean Rice (Platter) | 1,580 | 51 | 80,580 |
| #163 | Singaporean Rice Family Pack (Large) | 4,100 | 13 | 53,300 |
| #161 | Singaporean Rice (Khass) *(chhupa)* | 2,900 | 18 | 52,200 |
| #162 | Singaporean Rice Family Pack (Small) | 2,750 | 14 | 38,500 |

**≈ 1,072,190 — dukaan ki sab se bari cheez, aur is ka koi kitchen ticket nahi banta.**
**Mashwara:** Fastfood KOT — **magar pehle shop se poochein**, mumkin hai rice ka apna counter ho jahan parchi ki zaroorat hi na samjhi gayi ho.

### cat#26 Chicken Biryani

| id | Product | Rate | Qty | Value |
|---|---|---:|---:|---:|
| #165 | Chicken Biryani (Small) | 400 | 263 | 110,025 |
| #166 | Chicken Biryani (Large) | 750 | 37 | 27,750 |
| #164 | Chicken Biryani (Sadi) | 250 | 29 | 7,250 |
| #168 | Chicken Biryani Family Pack 6 Pcs | 2,270 | 3 | 6,810 |
| #169 | Extra Chicken Pcs | 200 | 4 | 800 |
| #167 | Chicken Biryani (Platter) | 2,000 | 0 | 0 |

**≈ 152,635. Mashwara:** Fastfood KOT (wohi "kitchen") — Singaporean Rice ke saath ek hi faisla.

### cat#29 Extras — mila jula, alag alag jagah

| id | Product | Rate | Qty | Value | Mashwara |
|---|---|---:|---:|---:|---|
| #194 | Arabic Rice *(chhupa)* | 300 | 28 | 86,200 | **Fastfood** (deal ka hissa, pakta hai) |
| #183 | Singaporean Sauce | 100 | 27 | 2,700 | **Fastfood** |
| #185 | Garlic Fried | 100 | 16 | 1,600 | **Fastfood** |
| #186 | Cheese | 100 | 15 | 1,500 | **Fastfood** ← client ne kaha |
| #191 | Extra Sauce | 100 | 9 | 900 | **Fastfood** |
| #204 | Plain Rice | 170 | 8 | 1,360 | **Fastfood** |
| #188 | Dinner Roll | 80 | 6 | 480 | **Fastfood** |
| #190 | Mayo Sauce | 80 | 4 | 320 | **Fastfood** ← shayad "Sauce 80" yehi hai |
| #189 | Coleslaw | 80 | 2 | 160 | counter (thanda, bana banaya) |
| #184 | Butter | 200 | 2 | 400 | counter |
| #193 | Extra Skewer | 625 | 1 | 625 | **BBQ / Grill** |
| #192 | Sizzling Charge | 200 | 1 | 200 | **koi nahi** — ye khana nahi, charge hai |
| #187 | Bun | 80 | 0 | 0 | counter |
| #195 | 3 Different Chatnies *(chhupa, 0)* | 0 | 0 | 0 | **koi nahi** |

### cat#28 Raita & Salad

| id | Product | Rate | Qty | Value | Mashwara |
|---|---|---:|---:|---:|---|
| #181 | Raita | 100 | 86 | 8,600 | counter (bana banaya) |
| #182 | Fresh Salad | 200 | 11 | 2,200 | **BBQ / Grill** ← client ne kaha |

### cat#27 Beverages — ✅ yahan station ki zaroorat NAHI

12 products, ≈ 144,570 (Soft Drink 345ml akela 88,920). Fridge se counter par diya jata hai — kitchen ka koi kaam nahi. **Jaisa hai waisa theek hai.**

### cat#36 Dessert — ✅ ghalib guman hai ke zaroorat nahi

`Cream Cocktail Cup` 46 / 9,200 aur `Half Pack` 12 / 7,200. Agar fridge se nikalta hai to counter theek; agar banaya jata hai to **Fastfood**. Shop se ek jumle me tay ho jayega.

---

## 10. Khulasa — kya kya karna chahiye

| Category | Ab | Mashwara | Kitna paisa |
|---|---|---|---:|
| Singaporean Rice | koi nahi | **Fastfood KOT** (tasdeeq ke baad) | 1,072,190 |
| Chicken Biryani | koi nahi | **Fastfood KOT** (tasdeeq ke baad) | 152,635 |
| Extras (chunida) | koi nahi | **Fastfood KOT** — naya chhota sar | ~95,060 |
| Extras → Extra Skewer | koi nahi | **BBQ / Grill KOT** | 625 |
| Raita & Salad → Fresh Salad | koi nahi | **BBQ / Grill KOT** | 2,200 |
| Beverages | koi nahi | **waisa hi rehne dein** | 144,570 |
| Dessert | koi nahi | shop se poochein | 16,400 |

Sab se ahem: **Singaporean Rice aur Chicken Biryani milā kar ~1.22 million ka mal bina kitchen parchi ke bikta raha hai.** Ye dono theek karna sab se pehle ka kaam hai — aur donon me **koi code nahi lagta**, sirf ek mapping.

---

## 11. Jo asal me kiya gaya — 3 September

Sab kuchh **data** hai. Koi migration, koi code, koi deploy nahi.

### 11.1 Pehle ek ghalti jo malik ne pakdi

Maine §9 me likha tha ke kuchh categories ka "KOT kisi station par nahi jaata". Malik ne kaha:
*"aisa nahi hosakta — punching counter bhi to station hi hain na."* **Wo theek the.**

Counter printers (T1–T4) ka `print_role` = `both` hai — wo bill bhi chhapte hain **aur KOT bhi**.
Mapping is ki gawah hai: `cat#25 Singaporean Rice` ke chaar `print_role = kot` mappings T1–T4 par
maujood hain. Yani **KOT hamesha nikalta tha**; sawal sirf ye tha ke **station** par nikle ya us
counter par jahan punch hua.

### 11.2 Nayi tarteeb

```
Extras (root)                → Fastfood KOT
   Cheese · Bun · Butter · Coleslaw · Dinner Roll
   Garlic Fried · Plain Rice · Arabic Rice · Sizzling Charge

   ├── Fastfood Sauce  (cat#42)  → Fastfood KOT
   │      Mayo Sauce · Extra Sauce · Singaporean Sauce
   │
   └── BBQ Sauce       (cat#40)  → BBQ / Grill KOT
          3 Different Chatnies · Extra Skewer · Fresh Salad · Raita

Raita & Salad (cat#28)       → ab khali
BBQ Sides     (cat#41)       → retire (khali ho gaya tha)
```

**Ek category ka ek hi station hota hai** — isi liye sauce alag alag saron me baithe.

### 11.3 Ek zaroori qadam jo aasani se reh jaata

Jis category ka **station** ho, us ka KOT counter par nahi nikalta — counter ko **Reminder** milti
hai (Chinese aur Bar-B-Que hamesha se aise hain). Extras pehle counter-only tha, to us ke chaar
counter-KOT mappings **band** kiye gaye. Warna ek cheez ki **teen** parchi nikalti: station KOT +
counter KOT + counter Reminder.

Aur har nayi category ke liye **chaar counter Reminder mappings** banane parte hain — na banayen to
un products ke liye counter khamosh ho jata hai.

### 11.4 Naya deal — ek product, do station

```
combo#75  Garlic Rice With Shashlik Bar-B-Que   1,650   category: Chinese (cat#10)
   ├── Garlic Rice (#209, chhupa)        → Chinese     → Fastfood KOT
   └── Shashlik Bar-B-Que (#210, chhupa) → Bar-B-Que   → BBQ / Grill KOT
purana product #68 → is_pos_visible = 0
```

**Deal ki category jaan boojh kar `Chinese` rakhi, `Deals` nahi.** Report deal ko us ki apni
category ke sar par file karti hai, to paisa **usi sar par rehta hai jahan pehle tha** — sirf ye
hota hai ke wo Items ki list se nikal kar Deals ki list me aa jata hai.

### 11.5 Report par asar

| | |
|---|---|
| Paisa / net sales / cash / ledger | **sifr** |
| Extras ke andar ki tabdeeliyan | **sar nahi hila** — root wahi Extras, aur report root par file karti hai |
| Deal | sar wahi `Chinese`; sirf section badla (Items → Deals) |
| ⚠️ **Raita + Fresh Salad** | Extras me chale gaye → **`Raita & Salad` ka sar khali**, us ka **10,800** (25 Aug–2 Sep) ab Extras ke sar par. Malik ki hidayat par. |

### 11.6 Tasdeeq — mapping table se nahi, asli engine se

Test order banaya, `PrintRoutingService::kotRoutesForSale()` se poocha, phir order **rollback**:

```
Cheese · Bun · Butter · Coleslaw · Sizzling · Garlic Fried
Plain Rice · Dinner Roll · teenon Sauce      → Fastfood KOT
Chatnies · Extra Skewer · Fresh Salad · Raita → BBQ / Grill KOT
Deal › Garlic Rice                            → Fastfood KOT
Deal › Shashlik Bar-B-Que                     → BBQ / Grill KOT
combo_header khud kisi parchi par gaya?       nahi (durust)
```

Yehi test **chaaron counters** par chalaya: station wali cheezein har baar apne station par gayin,
aur counter wali **usi counter par jahan punch hua** — T1 par T1, T2 par T2, T3 par T3, T4 par T4.
Counter ka rasta kisi ek printer par chipka hua nahi.

### 11.7 Routing ka koi gap?

**Koi nahi.** Poore catalogue me ek bhi product aisa nahi jis ka KOT kahin na jata ho, aur har deal
ke components ki routing bhi maujood hai.

### 11.8 Jo abhi baqi hai

- **Cashiers ko Ctrl+F5** — nayi categories aur naya deal tab hi POS par nazar aayenge.
- `BBQ Sauce` ab ek **category** hai. Agar dukaan waqai "BBQ Sauce" naam ki koi bikne wali cheez
  bhi rakhti hai, to us ka naam aur qeemat chahiye — routing pehle se BBQ par set hai.
- Pehla asli bill punch kar ke ek nazar dekh lena.

**UI document:** https://claude.ai/code/artifact/bdc48c17-9371-4127-b682-f0b59acc966e
