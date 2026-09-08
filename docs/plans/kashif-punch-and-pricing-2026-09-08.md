# Kashif Kitchen — punch screen aur pricing: kya toota hai, kya karna hai

**Date:** 2026-09-08 · **Tenant:** `kashifkitchen` (live) · **Status:** PLAN — koi code laga nahi, koi data badla nahi
**Worktree:** `pos-saas-catering` (canonical `pos-saas` finance workstream ke liye mahfooz hai)
**Prod is waqt:** `1b10a62` · full suite 1230/1230 green

Sab kuch live tenant ke asal data par jaanch kar likha gaya hai — andaza kahin nahi.

---

## 0. Ek nazar me

| # | kaam | qism | faisla chahiye? |
|---|---|---|---|
| 1 | Gosht ka formula — material ka charge material ki miqdaar par | **DATA** (234 rows) | ✅ owner ne haan ki |
| 2 | Edit me product badlo → row duplicate ban jati hai | code (bug) | nahi |
| 3 | Row ki tarteeb badalne ka tareeqa (upar/neeche) | code (naya) | tareeqa chunna hai |
| 4 | Print par `(us)` → `(CAT)`, party → `(PAR)` | code (lafz) | nahi |
| 5 | Recalculate pehle poochhe ke save kar lein | code (bug) | nahi |
| 6 | A4 **PDF** ka button | code (naya) | Urdu ki hadd batani hai |

---

## 1. Gosht ka formula — `per_material_unit`

### Owner ka model, unhi ke alfaz me

```
28 kg biryani me 48 kg gosht
1 kg biryani = 200   ->  200 x 28
1 kg gosht   = 400   ->  400 x 48
total = x + y
```

Yaani **making dish ki miqdaar par, gosht apni miqdaar par.**

### Aaj kya ho raha hai

Code dono tareeqe pehle se jaanta hai (`CateringEstimateLineCostBlock::computeAmount`):

| `rate_basis` | charge |
|---|---|
| `per_material_unit` | `billableQty × rate` — **gosht** ki miqdaar par |
| `per_dish_unit` | `rate × dishQty` — **dish** ki miqdaar par |

Aur live catalogue par:

```
material blocks: 234   →   per_dish_unit  234
                            per_material_unit  0
```

**Ek bhi block sahi basis par nahi hai.**

### Ye kabhi zahir kyun nahi hua

Legacy book me `OrderRate = MeatRate + ServiceRate` **per KG dish** tha aur ratio **1:1**. Us soorat me dono basis ka **hu-ba-hu ek hi jawab** aata hai. Farq sirf tab khulta hai jab operator gosht ki miqdaar **barha** de — jo is kaarobar ka rozmarra hai.

### Live saboot

`EV-20260908-0001` ki biryani line par:

| | abhi | hona chahiye |
|---|---|---|
| Making | 28 × 1,200 = 33,600 | 33,600 |
| Beef | **28** × 1,450 = 40,600 | **42** × 1,450 = 60,900 |
| line | 74,200 | **94,500** (rate 3,375) |

Client ne 3,375 **haath se** likh kar 94,500 nikala. Durust basis par system khud yehi nikalta.

### Gosht wale item pehchane kaise jayenge — pehchan ki zarurat hi nahi

Owner ka sawaal tha, aur data khud jawab deta hai. Saare 234 material blocks **sirf gosht** par hain:

```
Chicken (Regular)  111   ·   Beef (With Bone)  68
Mutton              46   ·   Chicken Boneless   9
```

Non-gosht items par material block **hai hi nahi** — unke paas sirf `charge` block hota hai:

```
Roti · Taftan - · Salad Bar Cold - 9 · Raita · Wonton · Cherry Crunch
   material = 0     charge = 1
```

Poore catalogue me **887 charge** aur **234 material** blocks hain. Update `WHERE block_type = 'material'` par chalti hai, is liye **887 charge blocks chhue tak nahi jaate**. Roti/nan/salad/raita/mithai par asar **mumkin hi nahi**.

### Amal

```sql
UPDATE catering_product_cost_blocks
   SET rate_basis = 'per_material_unit'
 WHERE block_type = 'material';         -- 234 rows
```

**Hifazat, jo script khud sabit karegi:** ratio 1 aur bina override ke dono basis ek hi figure dete hain, is liye **kisi product ka apna rate hilna nahi chahiye**. Script 234 me se har product ka `rateFor()` pehle aur baad me lekar milaati hai, aur koi bhi hile to naam le kar batati hai.

**Mojooda orders par asar nahi.** Har saved line apne `catering_estimate_line_cost_blocks` (snapshot) rakhti hai jisme uska apna `rate_basis` hai. Master badalne se purani lines nahi badaltin — naya formula **nayi lines** par lagega. (Owner ka apna faisla bhi yehi hai.)

**Wapas lena:** abhi sab `per_dish_unit` hain, is liye ek ulta update poori haalat bahal kar deta hai.

---

## 2. Edit me product badalna — row duplicate ban jati hai

Live par saboot: `Chicken Karahi Shanwari` ki **teen** ek jaisi rows, 40 KG ki.

### Do alag kharabiyan, dono zaroori

**(a) `punchPick` edit bhool jata hai.** Har product select par wo bilkul naya `punch` object banata hai:

```js
punch = { productId: ..., name, ... };   // editRow, editIdx, editSaved sab gum
```

`punchCommit()` dekhta hai `if (punch.editRow) { punchCommitEdit(); }` — editRow hai hi nahi, is liye wo **nayi row** bana deta hai aur purani apni jagah rehti hai.

**(b) `punchCommitEdit` dish likhta hi nahi.** Saved row wale hisse me qty, materials, instructions aur rate stage hote hain — magar `product_id` / `item_name` kabhi nahi. Sirf (a) theek karna **is se bhi bura** hota: row purana naam pehne rehti aur naye dish ki laagat chipak jati.

### Server pehle se tayyar hai

`CateringEstimateService::saveDraftLines` me likha hai:

> *"A row whose product changed is a different dish. Its old costing explains nothing about the new one, so it starts again."*

Yaani fix **poora client-side** hai.

### Jagah barqarar rahegi

Un-saved (`punch-row`) wale raaste par pehle se `row.replaceWith(...)` hai — wo apni jagah par hi badalti hai. Saved row par bhi wahi usool: row **wahin** update hogi jahan hai. Row 1 row 1 hi rahegi.

### Kya badlega

- `punchPick` edit ki pehchan (`editRow`/`editIdx`/`editSaved`) aage le jayega, aur **qty dobara nahi poochhega** (dish badalne ka matlab miqdaar dobara likhna nahi).
- `punchCommitEdit` product badalne par row par naya `product_id`, `item_name`, Urdu naam, unit aur nazar aane wala naam likhega, aur **purane dish ka breakdown hata dega**.

**Guard:** fix hata kar RED karke dekha jayega, phir bahal.

---

## 3. Row ki tarteeb — upar/neeche, aur print bhi usi tarteeb par

### Achhi khabar: bunyaad pehle se maujood hai

- `saveDraftLines` me `'sort_order' => $index` — yaani **jis tarteeb me lines post hoti hain, wahi sort_order ban jata hai**.
- `CateringEstimate::lines()` par `orderBy('sort_order')` hai.
- Documents `$estimate->lines` par chhapte hain.

Is liye screen par row upar/neeche karke Save Estimate karne se **print ki tarteeb khud wahi ho jayegi**. Koi migration, koi naya column darkar nahi.

### Tareeqa — do me se ek chunna hai

| | kya hota hai | achhai | kharabi |
|---|---|---|---|
| **A. Upar/neeche ke button** | har row par ▲ ▼ — ek click, ek qadam | keyboard se bhi chalta hai, touch par bhi, koi library nahi | lambi list me kai click |
| **B. Drag & drop** | row pakad kar khींch dein | tez, qudrati | ek chhoti JS library, aur mobile/touch par nazuk |

**Tajweez: A** — is screen ka poora mizaj keyboard ka hai (punch bar, Ctrl+Enter, Tab). Chahein to baad me B bhi saath laga sakte hain.

⚠️ **Ek baat jo dhyan me rakhni hai:** row hilane ke baad `lines[i]` ke index dobara ginne parenge, warna server par tarteeb DOM ke bharose rahegi. Ye chalta to hai, magar bharose par chalna aur likh kar chalna alag baat hai — index dobara likhe jayenge.

---

## 4. Print par `(us)` aur `(customer)` ke lafz

Abhi quotation aur kitchen sheet dono ek hi partial se chhapte hain
(`documents/partials/line-materials.blade.php`), aur wo `us` / `customer` likhta hai:

```
Chicken (Regular) 15 KG (us)
Beef (With Bone) 5 KG (us 3, customer 2)
```

Owner chahte hain:

```
(us)        ->  (CAT)     — catering, yaani hum
(customer)  ->  (PAR)     — party, yaani gaahak
```

Ek hi jagah badalna hai, dono documents theek ho jayenge — yehi us partial ka maqsad tha.

---

## 5. Recalculate Cost pehle poochhe

`Recalculate Cost` **save-shuda** quotation se chalta hai aur uske jawab se poora workspace dobara banta hai. Jo rows punch to hui magar save nahi hueen, wo us jawab me hain hi nahi — is liye **chup-chaap ud jati hain**.

Screen par unki pehchan maujood hai: `tr.punch-row` (nayi un-saved) aur `.punch-staged` (edit hui saved row).

**Kya hoga:** button dabate hi, agar aisi rows hain, to rukega aur batayega — *"X nayi aur Y edit ki hui row abhi save nahi hui; Recalculate unhein bahaa de ga. Pehle Save Estimate (Ctrl+S) karein."* Jari rakhne ka rasta khula rahega, magar ab wo **faisla** hoga, haadsa nahi.

Handler **capture phase** par lagega, kyunki ajax pipeline bhi `document` par `submit` sunta hai aur ise us se pehle rukna hai.

---

## 6. A4 PDF ka button

Ghar me tareeqa pehle se maujood hai — `SalesReportDocumentService::pdf()`: dompdf, `setPaper('a4','portrait')`, wahi Blade jo screen par chhapti hai.

**Kya banega:** `GET /catering/documents/estimate/{estimate}/pdf` → wahi estimate view → dompdf → PDF.

⚠️ **Do baatein pehle se:**
1. **Naya route = naya permission.** `deploy.sh` sirf Owner ko deta hai; baqi roles ko additive grant + `system:clear-tenant-permission-cache` chahiye. Kashif Kitchen par filhal sirf Owner hai, is liye wahan masla nahi — magar usool yaad rahe.
2. **Urdu PDF me theek nahi chhapega.** dompdf Nastaliq shaping nahi karta. PDF ka faida `en` aur `both` par hoga; **Urdu ke liye browser ka Print behtar rahega** (wo asal font istemal karta hai). Ye hadd chhupani nahi chahiye.

---

## 7. Tarteeb-e-amal

| # | qadam | kyun is tarteeb me |
|---|---|---|
| 1 | Gosht ka formula (data, 234 rows) | sab se bara paisa; owner ne haan ki; nayi lines foran durust hongi |
| 2 | Edit/product swap | abhi quotation kharab kar raha hai (teen duplicate rows) |
| 3 | Print ke lafz (CAT/PAR) | client ke saamne jaane wala kaghaz |
| 4 | Recalculate ka warning | kaam zaya hone se bachata hai |
| 5 | Row ki tarteeb (▲▼) | naya feature |
| 6 | A4 PDF | naya feature |

1 data hai — deploy nahi chahiye. 2–6 code hain, ek hi deploy me ja sakte hain.

**Har code fix ke saath:** guard, jise fix hata kar **RED** karke dekha jayega phir bahal — aur har Blade compile karke.

---

## 8. Is document ke liye kya chala

Sirf padhne wali queries live par (block counts, rate_basis, material naam, line snapshots), aur repo me code parha gaya. **Koi data nahi badla, koi code nahi laga, koi deploy nahi hua.**

Wo mass update jo §1 me hai, ek dafa chalayi gayi thi aur **system ne rok di** — 234 rows par paise ke maani badalne ke liye owner ki saaf ijazat darkar thi. Wo ab mil chuki hai.
