# THERMAL-ITEM-LAYOUT-1 — Item aur Item+Category ki thermal shakl

**Tareekh:** 2026-09-03
**Halat:** plan + preview manzoor · **code abhi nahi likha, deploy nahi hua**
**Preview (asli data):** https://claude.ai/code/artifact/2657d50f-61b3-4eca-bbad-97b9dd601adc
**Scope:** sirf **chhapne ki shakl**. Hisab, Sold/Ret/Net, grouping, queries, filters, business logic — kuchh nahi.

---

## 1. Abhi kya galat hai

Malik ne roll par nazar daal kar sat cheezein ginwayin. Live output se tasdeeq (Kashif, 2 Sep, `Rolls`):

```
ROLLS
Qty             74           1          73      ← category ka total ITEMS SE PEHLE
Amt      31,160.00      370.00   30,790.00
------------------------------------------
 BEEF BOTI ROLLS
 Qty            10           0          10      ← chhote sar ka total bhi pehle
 Amt      3,850.00        0.00    3,850.00
------------------------------------------
  BEEF BOTI CHATNI ROLL
  Qty            6           0           6
  Amt     2,220.00        0.00    2,220.00
------------------------------------------      ← HAR entry ke baad line
```

| # | Masla |
|---|---|
| 1 | Lambe naam kaghaz se bahar jaate ya agli line par tootte — `(1 kg)` akela agli line par |
| 2 | Category, chhoti category aur item — teen tehen nazar me alag nahi (indent 0/1/2) |
| 3 | Har entry ke baad line — bina maqsad ke, kaghaz kharch |
| 4 | Category ka total **apne items se pehle** chhapta hai |
| 5 | Sar ki koi apni pehchan nahi (na solid, na dotted line) |
| 6 | Sab naam CAPITAL — sar aur item ek jaise |
| 7 | Kaghaz ki chaurai poori istemal nahi hoti |

---

## 2. Nayi shakl

```
CHICKEN BIRYANI                     ← 0 indent, bold, CAPITAL
================================    ← SOLID

  Biryani Chicken                   ← 2 indent, bold
  ............................      ← DOTTED

      Chicken Biryani (1/2 kg)      ← 6 indent, normal
      Qty            22        0        22
      Amt         7,260        0     7,260

      Chicken Biryani (1 kg)        ← beech me koi line NAHI
      Qty             2        0         2
      Amt         1,300        0     1,300

  TOTAL                             ← items ke BAAD, chhote sar ke indent par
  Qty               24        0        24
  Amt            8,560        0     8,560
  ............................      ← child block band

TOTAL                               ← poori parent ke BAAD, 0 indent
Qty                 34        0        34
Amt             10,560        0    10,560
================================    ← parent band
```

**Item List (flat)** me hierarchy nahi, magar wohi chaurai, wohi truncation, wohi alignment, aur
entry ke beech koi line nahi.

---

## 3. Chaar faisle jo malik ki misal se hatte hain — aur kyun

### 3.1 Kaghaz 42 columns, 48 nahi

Misal 48 ki thi. Asli printable width **80mm = 42**, **58mm = 32**. 48 par banate to har line
kaghaz se bahar chali jaati — bilkul wohi masla jo theek karwana maqsad hai.

### 3.2 Roll par bhi paise BINA decimals

Screen preview pehle se `7,260` chhapta hai, roll `7,260.00`. Decimals hatne se **har column teen
character chhota** ho jata hai — isi se 6 ka indent aur teenon hindse ek saath fit aate hain. Saath
hi dono raaste (roll aur preview) ek jaise ho jate hain.

⚠️ Ye poore thermal report par lagega, sirf in do section par nahi — warna ek hi parche par
CATEGORIES me `31,160.00` aur ITEMS me `31,160` chhapta, jo aur bura lagta.

### 3.3 Truncate par aakhri "(size)" MEHFOOZ

Malik ne likha tha `Beef Khatri Biryani Special (1 kg)` → `Beef Khatri Biryani Spe...`

Magar Khatri me chaar naam hain: `(1/2 kg)`, `(1 kg)`, `Special (1 kg)`, `Special (1/2 kg)`. Plain
`...` se **do do row ka naam bilkul ek jaisa** ho jata — sirf hindse alag, aur report parhi hi na
jaati. Is liye size aakhir me bacha rehta hai:

```
2 Pcs Crispy Fried Chicken (Spicy, With Fries)   (46 characters)
→  2 Pcs Crispy... (Spicy, With Fries)           (42 columns, 6 indent)
```

### 3.4 Chaurai aur indent DATA se nikalte hain, andaze se nahi

Pehli koshish me 6 ka indent har jagah rakha. 42 par theek chala — **32 par `Qty`/`Amt` ke label hi
ghayab** ho gaye, kyunke teen hindson ke baad label ki jagah hi nahi bachi.

Ab tarteeb ye hai:

1. Us report ka **sab se lamba hindsa** naapo → column ki chaurai = us par ek space.
2. Jo bacha wo label + indent ka budget hai.
3. Indent utna hi jitna `Qty` (3 char) + ek space ke baad bache.

Nateeja: **80mm par item 6 / child 2**, **58mm par item 4 / child 1**. Dono par ek bhi line width se
bahar nahi.

### 3.5 Flat category par beech wala sar nahi

Malik ki misal me `SINGAPOREAN RICE` ke neeche dobara `Singaporean Rice` ka sar tha. Jis category ke
bache hi nahi, wahan wo qatar sirf ooper wala naam dobara likhti hai — kaghaz kharch, kehti kuchh
nahi. Ye `ITEMS-BY-CATEGORY-1` ka pehle se tay shuda usool hai (`nested` flag). **Malik kahen to
daal diya jayega.**

---

## 4. Kahan lagega

| File | Kya |
|---|---|
| `app/Support/ThermalLayout.php` *(naya)* | **shared helpers** — chaurai, `fit()` truncation, figure column ka hisab, indent, separator |
| `app/Services/Printing/EscPosPayloadService::buildReport()` | asli roll ke bytes — ITEMS aur ITEMS BY CATEGORY dono block |
| `resources/views/tenant/reports/center/print.blade.php` (`$isThermal`) | screen preview + PDF |

Ek hi helper set dono ke liye — warna dono raaste waqt ke saath alag ho jate hain, jaise indent wale
mamle me ho chuka hai (roll par space chalta tha, HTML use kha jata tha).

**Khud-ba-khud chhe jagah:** Report Center thermal, Send-to-Network, POS Quick Report, raat wali
email, aur dono ka preview. **A4 / PDF ka layout achhoot.**

---

## 5. Kya nahi badlega

- Koi hindsa, quantity, Sold / Ret / Net, category ya sub-category ka hisab
- `SalesReportEngine` ka ek bhi function
- Koi query, filter, migration, permission
- A4 layout

**Saboot ka tareeqa:** deploy se pehle aur baad ka **paise ka snapshot** (naam se sorted, dono
tenant × do din) — diff khali hona chahiye.

---

## 6. Jaanch jo har build par chalegi

1. Koi line kaghaz ki chaurai se **bahar na ho** — 42 aur 32 dono par
2. Koi naam **wrap na ho**
3. Har category ka total **apne items ke baad**
4. Guard test dono raaston par
5. Poori suite green

---

## 7. Doosri qist — `0de76fb` ke baad jo reh gaya tha (3 Sep)

`0de76fb` par sirf **roll (ESC/POS)** wala raasta durust hua tha. Dukan ne jo dekha wo **preview /
PDF** ka raasta tha, is liye wahi purani shikayat dobara aayi:

> "deals mai bilkul b line brakup nhi hai bht bura lag ra hai"
> "item + cat mai abhe b items width say bahir nikal rahe"

Do alag wajhein, dono blade me:

**(a) CATEGORIES aur DEALS blade me chhoot gaye.** DEALS ka purana block chaar-column table par
`colspan="7"` ka nanga `<strong>` chhapta tha — kahin koi lakeer nahi. Ab dono blocks wahi shakl
lete hain jo ITEMS BY CATEGORY leta hai: sar, uske neeche uske afrad, phir uska total.

**(b) Naam ek hi chaurai par kat rahe the.** `fit()` ko har naam ke liye `columns - 6` diya ja raha
tha — chahe wo parent ho, child ho ya item, jab ke un ka indent alag hai. 6 andar baitha item apni
jagah se bahar nikal jata tha aur browser use tor deta tha. Ab indent ek **integer** hai aur
`fit(name, cols - indent)` har satah par apna hisab karta hai. `tr.name td { white-space: nowrap }`
peti ke saath belt hai.

### Us ke saath teen aur cheezein jo dukan ne kahin

| Kaha | Kiya |
|---|---|
| "category mai qty aur amount rows ki dotted line hatado" | Har `lvl-*` row ka `border-top` sifar. Ek entry ke Qty aur Amt ke darmiyan ab kuch nahi — wo ek hi parhne ki do adhiyan hain. |
| "har category end pe total ke baad bold line" | Lakeer ab **total ke neeche** hai, upar nahi. Amt row ko apni class (`amt-row`) mili taake CSS us akhri satar ko pehchan sake — pehle ye `total` class par tika tha, jo naam wali satar par bhi lagti hai. |
| Childless category do satar kha rahi thi | Jis block ka sar pehle chhap chuka ho wo `$tEntry` ko khali naam deta hai, aur khali naam ab **koi satar nahi** banata. |

### Indent ka usool (dono raaston par ek)

Item apne **oopar wale** se ek qadam andar hota hai — sub-head se, agar parent ke bachche hain;
warna khud parent se. Flat category apne naam ke chaar characters ek aise qadam par kharch kar rahi
thi jo kisi se door nahi ja raha tha. DEALS bhi isi usool par: deal ke sar ke neeche koi sub-level
nahi, to deals ek hi qadam andar.

### Guard

`tests/MySql/ThermalItemLayoutBladeMySqlTest.php` (8) — **blade** ka apna guard, kyunke purana
guard sirf roll parhta tha aur isi liye ye kharabi pakad nahi paya. Dono raaston ka guard ab alag
alag maujood hai. Donon assertion ko tor kar dekha gaya (khali satar wapas dali → RED; `nowrap`
hatai → RED), phir bahaal kar ke green.
