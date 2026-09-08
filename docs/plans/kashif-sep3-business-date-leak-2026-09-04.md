# Kashif Food — 3 September ka net sale kya hona chahiye tha (research)

**Tareekh:** 2026-09-04 · **Tenant:** kashiffood (#348) · **Branch:** 1
**Halat:** RESEARCH — prod par kuch nahi badla. Sab read-only.
**Snapshot:** 2026-09-04 **23:04 PKT** — ye figures abhi bhi **barh rahe hain** (neeche §6).

---

## 1. Kya poocha gaya

> "3 tareekh ki exact kya net sale honi chahiye thi… mere ilm ke mutabiq counter 4 ka saara data
> ya Q2 leta hai ya Q3."

Owner ka andaza **durust nikla**, aur uska nateeja andaze se bara hai.

---

## 2. Jarr — ek shift jo band nahi hui

**Shift #24 (DTQ Floor T4)** 3 Sep 13:45 PKT ko khuli aur **kabhi band nahi hui**. Uski
`business_date` **2026-09-03** par jami hui hai.

Us raat baqi teenon counters ne apni apni shift **alag alag** band ki — 4 Sep 01:05 (Delivery),
01:37 (DTQ 2), 01:41 (DTQ 3). Teen alag waqt, teen alag users: ye "Close Shift" wale single button
ka nishan hai, "Close Branch" ka nahi. Us lamhe #24 par **koi held bill nahi tha**, yani wo band ho
sakti thi — kisi ne ki nahi.

> Ye shift **DTQ 2 Counter** ne kholi thi, chalayi Floor T4 ne. Shayad isi liye kisi ne apni
> zimmedari nahi samjhi.

## 3. Aaj kya ho raha hai — mechanism (code se saabit, qayas nahi)

```
Bill T4 par khulta hai        →  shift #24 (business_date 2026-09-03)
                              →  bill ki business_date = 2026-09-03  [JAM GAYI]
Paisa DTQ 2 / DTQ 3 par       →  shift_id aur terminal_id BADAL jate hain
                              →  business_date NAHI badalti
Nateeja                       →  aaj ka paisa 3 September me
```

Code ki teen jaghen:

| Jagah | Kya kehti hai |
|---|---|
| `ShiftService` (docblock + open) | `business_date` shift khulte waqt **freeze**, baad me immutable |
| `HeldSaleController:464-466` | naye bill ki `business_date` = table session ki, warna **shift ki** |
| `HeldSaleController:645` | update par `$sale->business_date ?? $businessDate` — **jami hui qayam rehti hai** |

**Chashm-deed saboot:** bill **#2111** (`HS-20260904170940-332`) meri pehli query me
`shift #24 · DTQ Floor T4 · held` tha; **bees minute baad** wohi bill `shift #28 · DTQ 2 · paid` —
aur uski `business_date` dono dafa **2026-09-03**.

---

## 4. Hisab (snapshot 23:04 PKT)

### 3 September

| | Bills | Billed | Returns | **NET SALE** |
|---|---:|---:|---:|---:|
| Report abhi jo dikha raha hai | 416 | 765,280.00 | 4,950.00 | **760,330.00** |
| **Hona chahiye tha** | **364** | **628,970.00** | 4,950.00 | **624,020.00** |
| Farq (asal me 4 Sep ka hai) | 52 | **136,310.00** | — | **136,310.00** |

### 4 September

| | Billed | Returns | **NET SALE** |
|---|---:|---:|---:|
| Report abhi jo dikha raha hai | 433,795.00 | 1,050.00 | **432,745.00** |
| Hona chahiye (ab tak) | 570,105.00 | 1,050.00 | **569,055.00** |

**Returns saaf hain.** 3 Sep ke chhon posted returns (4,950) sab **waqai 3 Sep ke bills** ke
khilaf hain — koi return ghalat din me nahi gira, is liye returns me koi adjustment nahi.

### Taqseem ka usool

`business_date = 2026-09-03` **aur** bill 3 Sep ko bana = us din ka asli kaam.
`business_date = 2026-09-03` magar bill **4 Sep ko bana** = aaj ka kaam jo purani shift ki wajah se
kal me gira.

> **Durusti:** pehle yahan likha tha ke "Kashif ka din UTC ki tareekh par jamta hai" — ye GHALAT
> tha. `TenantClock::businessDateForOpening()` **Karachi** ki calendar date leta hai (`Asia/Karachi`),
> aur wohi shift par jam jaati hai. Taqseem wala usool phir bhi durust hai: shift #24 ke leak hue
> saare bills 4 Sep 12:44 PKT ke baad bane, is liye `created_at >= 4 Sep 00:00 UTC` un sab ko theek
> pakadta hai aur 3 Sep ki asli raat wale bills ko chhota nahi.

---

## 5. Owner ka andaza kahan tak durust tha

> "counter 4 ka saara data ya Q2 leta hai ya Q3"

**Bilkul durust.** 3 Sep ko terminal 4 par sirf **2 cancelled bills** the — ek bhi paid nahi. T4 ka
kaam hamesha Q2/Q3 par settle hota hai. Isi liye:

- **3 September ka apna figure (624,020) mukammal hai** — T4 ka us din ka kaam pehle hi Q2/Q3 ke
  andar shamil hai. Us din kuch **kam** nahi hua.
- Masla ulta hai: 3 September me **136,310 zyada** ghus gaya hai, jo **4 September ka** hai.

---

## 6. ⚠️ Ye figure abhi bhi barh raha hai

| Waqt (PKT) | 3 Sep ka net sale |
|---|---|
| 22:34 | 746,790.00 |
| 23:04 | **760,330.00** |

Aadhe ghante me **13,540 barha** — kyunke T4 ke bills abhi bhi Q2/Q3 par settle ho rahe hain.
Iske ilawa T4 par **abhi bhi held bills** hain (22:47 par 9 bills / 39,850) aur **19 khuli tables**;
un ka har rupya bhi **3 September** me hi girega.

---

## 7. Ilaaj

### (a) Aaj raat — sab se pehle

T4 ke held bills nipta kar shift **#24 band karni hai**, aur naya kaam nayi shift par ho.
⚠️ Us se pehle **Close Branch mat dabana** — chaaron shifts ek hi transaction me band hoti hain,
T4 par rukawat aate hi **poori cheez wapas** ho jayegi aur DTQ 2 / DTQ 3 bhi khuli reh jayengi.
Uske bajaye pehle T4 ke bills settle/cancel karein, tables band karein, phir Close Branch.

### (b) Tareekh ki durusti

Un 52+ bills ki `business_date` **2026-09-03 → 2026-09-04** karni hogi. Ye theek wohi cheez hai
jiske liye `business_date` bana hai, aur isse:
- 3 Sep: 760,330 → **624,020**
- 4 Sep: 432,745 → **569,055** (aur T4 ke baqi bills settle hone par aur barhega)

Shart: ye **service khatam hone ke baad** ho, warna beech me bante bills phir se chhoot jayenge.
Before/after snapshot dono dinon ka lena zaroori hai.

### (c) Asal khaami — ye dobara na ho

System shift ko **agli tareekh me chalte rehne se rokta nahi**. Do me se koi bhi kaafi hai:

1. **Warning:** POS par aisi shift kholte waqt saaf batana — "ye shift 3 Sep ki hai, aaj 4 Sep hai".
2. **Rukawat:** jis shift ki `business_date` aaj se purani ho, us par naya bill banne hi na dena
   (jaise mandatory-open-shift hai).

(1) narm hai, (2) mazboot. Mera mashwara: **dono** — POS par warning, aur din badalne par nayi
shift lazmi.

---

## 8. Aur ek baat jo isi tehqeeq me nikli

Teenon counter users (`DTQ 2 Counter`, `DTQ 3 Counter`, `Floor T4 Counter`) ke paas **teenon**
dine-in terminals ka access hai — `Floor T4 Counter` ka role "Dine In (**Restricted**)" hone ke
bawajood. Yani har cashier doosre ka daraz band kar sakta hai aur uska counted cash khud daal sakta
hai. Cash ki zimmedari ke lehaz se ye kamzori hai. Sirf `terminal_user` ki rows ka kaam hai, code ka
nahi.
