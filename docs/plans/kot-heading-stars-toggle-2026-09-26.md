# KOT-HEADING-STARS-1 — `*** KOT #1 ***` ke sitare, aur wo per-branch qaabu me

**Tareekh:** 2026-09-26
**Halat:** RESEARCH + PLAN — **koi code nahi badla, prod par kuch nahi chhua**
**Tenant:** `khatribiryani` (maang), magar setting har tenant/branch ke liye
**Farmaish:** parchi par likha tha — *"chohta"* aur *"star remove"*

---

## 1. Ab tak kya ho chuka (setting se)

Client ki parchi par do nishan thay:

| parchi | likha tha | matlab |
|---|---|---|
| KOT #1 DELIVERY | header block par ✗ + "chohta" + "star remove" | header chhota, aur `***` hataao |
| KOT #1 DINE IN | `TABLE: 8 / WAITER: HAMZA` par nishan + "thora chhota, par bold" | ye satarein thodi chhoti, magar bold rahein |

**Dono me se ek hissa bina kisi code ke ho chuka hai** (25 Sep, sirf Khatri ki layout row):

- `header_text` : `*** KITCHEN ORDER TICKET ***` → **`KITCHEN ORDER TICKET`**
- `kot_font_size` : **18 → 17** — yani `w2 h2` (lamba + chaura, 21 chars) se `w1 h2` (sirf lamba, poore 42 chars)

`TABLE:` / `WAITER:` pehle se **bold** hain (`scaled($text, $big, bold: true)`) aur wohi `kot_font_size` par chalte hain — is liye 18→17 ne unhein theek "thora chhota, par bold" kar diya.

**Jo bacha:** `*** KOT #1 ***` aur `** DELIVERY **` ke sitare. Ye kisi setting se nahi aate — **code me likhe hue hain.**

---

## 2. Sitare bante kahan hain

```
EscPosPayloadService::kot()            ← ASLI PARCHI (thermal)
  :1001  'cancel'    => '*** CANCEL KOT #n ***'
  :1002  'addition'  => '*** ADDITION KOT #n ***'
  :1003  'duplicate' => '*** DUPLICATE KOT #n ***'
  :1004  default     => '*** KOT #n ***'
  :1012  '** ' . ORDER TYPE . ' **'

kot.blade.php                          ← BROWSER / PREVIEW
  :78-84  CANCEL/ADDITION/DUPLICATE/KOT #n   ← sitare hain hi NAHI
  :90     '** ' . ORDER TYPE . ' **'
```

### 🔎 Ek purana ikhtilaf jo isi tehqeeq me nikla

**Preview aur kaghaz pehle se alag hain:**

| | `KOT #1` | `** ORDER TYPE **` |
|---|---|---|
| thermal (asli parchi) | `*** KOT #1 ***` | `** DELIVERY **` |
| browser / preview | `KOT #1` — **bina sitaron ke** | `** DELIVERY **` |

Yani jo maalik screen par preview me dekhta hai, wo kaghaz se pehle hi mukhtalif hai. Ye mere kaam se nahi bana; pehle se aisa hai. Neeche ka hal in dono ko **qareeb** laata hai, door nahi karta.

### Dayre se BAHAR (jaan-boojh kar)

`** ORDER TYPE **` do aur jagah bhi chhapta hai — `receipt()` (:766) aur `buildReminder()` (:278). Ye **alag documents** hain aur client ne un ka zikr nahi kiya. Un ki apni layout row hoti hai (`document_type` = `receipt` / `reminder`), is liye KOT ki row par lagaya gaya switch un par asar dalta hi nahi. Baad me chahein to wohi switch un rows par bhi laga sakte hain — us din ka kaam us din.

---

## 3. Hal — sitare **hata** nahi rahe, unhein **switch** ke peeche rakh rahe hain

⚠️ **Sitare seedhe hata dena ghalat hoga.** Ye code chaaron chalti hui businesses ki parchi chhapta hai. Khatri ne kaha hai; Kashif Food, Tawakal aur Kashif Kitchen ne nahi. Un ki parchi bina poochhe badal dena be-insafi hai.

Is liye: **ek naya per-branch layout switch**, jis ki default qeemat **aaj wali soorat** hai.

```
show_heading_stars   boolean   default TRUE
```

- **TRUE (default)** — aaj jaisa: `*** KOT #1 ***`, `** DELIVERY **`. Deploy ke din **kisi ki bhi parchi nahi badalti.**
- **FALSE** — `KOT #1`, `DELIVERY`. Khatri par ye band kar denge.

Ye is codebase ka apna tareeqa hai: `show_column_dividers` (default OFF, kyunki aaj kisi parchi par lakeerein nahi) aur `show_category_header` (default ON, kyunki aaj chhapta hai) bilkul isi tarah aaye thay. Migration ka apna comment bhi yehi kehta hai — *"no tenant's printed output changes on deploy"*.

---

## 4. Har wo file jo isi pass me chhuni hai

`show_category_header` ka poora raasta follow kiya — naya switch theek unhi jagah lagega:

| # | file | kya |
|---|---|---|
| 1 | `database/migrations/tenant/…_add_kot_heading_stars_toggle.php` | naya column, `default(true)`, `hasColumn` guard ke sath |
| 2 | `app/Models/Tenant/ReceiptLayoutSetting.php` | `$fillable` + `casts` + **`TOGGLE_FIELDS`** |
| 3 | `app/Http/Controllers/Tenant/ReceiptLayoutController.php` | ek validation satar. Save khud ho jayega — controller `TOGGLE_FIELDS` se uth-ta hai |
| 4 | `resources/views/tenant/printing/layouts/_form.blade.php` | ek array entry (label) |
| 5 | `app/Services/Printing/EscPosPayloadService.php` | `kot()` ka heading aur order-type switch ke peeche |
| 6 | `resources/views/tenant/printing/documents/kot.blade.php` | order-type line usi switch ke peeche |
| 7 | `tests/MySql/…` | guards (neeche) |

⚠️ **#3 aur #4 bhool jana sab se aasan ghalti hai** — column ban jata, thermal maan lete, magar operator ko us tak pahunchne ka koi raasta hi na hota. `TOGGLE_FIELDS` me daalna lazmi hai, warna controller us field ko boolean me badalta hi nahi aur checkbox khali chhorne par purani qeemat chipki reh jati.

---

## 5. Guards (jo likhne hain)

1. **Default par kuch nahi badalta** — naya switch chhue baghair thermal KOT me `*** KOT #1 ***` aur `** DELIVERY **` waise hi rahein. Ye sab se ahem guard hai: deploy ke din chaaron businesses mehfooz.
2. **Switch band karne par sitare jayen** — `KOT #1`, `DELIVERY` — aur naam/number bilkul na badle.
3. **Sirf usi branch par** — do branch wali layout rows par alag alag qeemat, aur har branch ko apni hi mile (Tawakal par do rows hain, ye asli soorat hai).
4. **Preview aur kaghaz ek jaisa bolen** — switch band hone par blade aur ESC/POS dono me sitare na hon.
5. **CANCEL / ADDITION / DUPLICATE** teenon variants par bhi wohi qaida (ye alag satarein hain, bhool jane ka poora mauqa hai).
6. **Receipt aur Reminder be-harkat** — KOT ki row par switch band karne se un ke `** ORDER TYPE **` par koi asar na ho.
7. **Operator ke liye raasta maujood** — Edit Layout ka safha waqai render ho aur us par ye toggle nazar aaye (form + controller wali linkage ka pehra).

---

## 6. Risk

| khatra | haqeeqat |
|---|---|
| doosre tenants ki parchi badal jaye | default `true` = aaj wali soorat. Guard 1 isi par pehra deta hai. |
| migration | **additive**, nullable nahi balke `default(true)`, aur `hasColumn` guard — dobara chalne par bhi mehfooz |
| operator tak na pahunche | guard 7; aur `TOGGLE_FIELDS` ek hi jagah hai, do jagah likhne ki zaroorat nahi |
| paisa / stock | ye mehez chhapai ka matn hai — koi journal, koi stock, koi order field nahi chhuta |
| blade toota to Close Branch jaisa 500 | har blade compile kar ke generated PHP lint hoga (`d746abe` ka sabaq) |

**Palatna aasan:** switch wapas ON, ya row ki qeemat badal dein. Migration additive hai.

---

## 7. Jo is pass me NAHI karunga

- Receipt aur Reminder ke `** ORDER TYPE **` — alag document, client ne nahi maanga
- Browser blade ke `KOT #n` par sitare **jorna** — wahan aaj hain hi nahi, aur jorne se har tenant ka preview badal jata. Ikhtilaf darj kar diya hai; switch band hone par dono khud ba khud mil jate hain.
- `TABLE` / `WAITER` ko heading se **alag** size dena — us ka koi field nahi, naya `info_font_size` chahiye. Filhaal `kot_font_size` 18→17 se client ki baat poori ho chuki hai; zaroorat par alag sprint.
