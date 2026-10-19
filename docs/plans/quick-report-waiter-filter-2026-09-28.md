# QUICK-REPORT-WAITER-NULL-1 — "Quick Report sirf mere order type ka dikha raha hai"

**Tareekh:** 2026-09-28
**Halat:** RESEARCH — **koi code nahi badla, prod par kuch nahi chhua**
**Screen:** POS → Quick Report (`/pos/quick-report/print`)

---

## 1. Pehla jawab: Quick Report me **kuch nahi badla**

`PosQuickReportController` aakhri baar **7 September** (`6c22fc7`) chhua gaya tha, aur
`SalesReportEngine` ka waiter wala filter **27 August** (`6994ac7`) ka hai. Pichle teen
hafte me is screen par ek satar nahi badli.

Aur Quick Report **user ke order type ya terminal se scope hota hi nahi**. Ye maine
maan kar nahi chhoRa — Khatri ke saaton users par engine chala kar dekha, ek jaise
filters ke sath:

| user | us ke order types | us ke terminals | nateeja |
|---|---|---|---|
| Khatri Biryani (owner) | sab | 1,2,3,4 | net 60,030 / 76 orders |
| mohsin | sab | 1,2 | net 60,030 / 76 orders |
| Delivery Counter | delivery | 1 | net 60,030 / 76 orders |
| Dine In Counter | dine_in | 3 | net 60,030 / 76 orders |
| Takeaway Counter | takeaway, quick_sale | 2 | net 60,030 / 76 orders |
| Quick Sale Counter | takeaway, quick_sale | 4 | net 60,030 / 76 orders |
| Account User | sab | sab | net 60,030 / 76 orders |

**Saat ke saat bilkul ek jaise.** Yani shikayat ki batayi hui wajah code me maujood nahi.

---

## 2. Magar shikayat sachi hai — asli wajah **waiter filter** hai

Dono screenshots nginx ke access log me mil gaye, theek ek minute ke faasle par:

| | Screenshot 1 — `10:26:57 UTC` | Screenshot 2 — `10:27:06 UTC` |
|---|---|---|
| `category_ids` | 3,14,15,1,9,10,2,12,13 | **bilkul wohi** |
| `waiter_ids` | *(khaali)* | **3,4,1,5,6,7 — saaray** |
| `order_types` | *(khaali)* | **dine_in, takeaway, quick_sale, delivery — saaray** |
| jawab ka size | 2938 bytes | **2803 bytes** |

Yani farq **category ka nahi** — dono me categories ek jaisi hain. Farq sirf itna hai ke
dusri baar **saaray waiters aur saaray order types tick** the.

Aur modal khud likhta hai: *"Leave all unticked = every waiter."* To **saab tick karna**
aur **kuch tick na karna** ek hi cheez honi chahiye. Chal kar dekha:

```
koi waiter/order-type tick nahi        qty=130   net=56,750.00
SAARAY waiter + SAARAY order type      qty=54    net=23,910.00   ← 57% ghayab
  sirf SAARAY order types              qty=130   net=56,750.00   ✅ theek
  sirf SAARAY waiters                  qty=54    net=23,910.00   ❌ yehi kharabi
```

**`order_types` theek kaam karta hai. `waiter_ids` nahi.**

---

## 3. Kharabi ki asal jarh: SQL me `NULL` kabhi `IN (...)` se match nahi karta

`app/Services/Reports/SalesReportEngine.php` (line 108, aur 223, aur 1004 par wohi shakl):

```php
->when($f['waiter_ids'], fn ($q) => $q->whereIn('o.restaurant_waiter_id', $f['waiter_ids']))
```

Jis order par koi waiter laga hi nahi (`restaurant_waiter_id IS NULL`), wo is shart se
**hamesha bahar** ho jata hai — chahe duniya ka har waiter tick kar do.

Aur POS me waiter sirf dine-in par lagta hai. 28 Sep ke aankray:

| tenant | order type | bila-waiter | kul |
|---|---|---|---|
| **khatribiryani** | takeaway | **38** | 38 |
| | delivery | **8** | 8 |
| | dine_in | 0 | 33 |
| | quick_sale | 0 | 3 |
| **kashiffood** | takeaway | **26** | 26 |
| | dine_in | **37** | 37 |
| | delivery | **18** | 18 |
| | quick_sale | 0 | 4 |

> **Khatri:** "saaray waiters" = sirf dine_in + quick_sale (36 orders), takeaway aur
> delivery ke 46 orders khamoshi se gayab.
> **Kashif Food:** har order bila-waiter hai — wahan **koi bhi** waiter tick karne par
> report **bilkul khaali** aayegi.

Isi liye dekhne wale ko laga ke "report sirf ek order type ki hai".

---

## 4. Ye ek mahine se chup-chaap chal raha tha

`pos_quick_report_settings` har user ki pasand **save** rakhta hai, aur modal use har
baar wapas laga deta hai (`index.blade.php:1824`). Khatri par:

| user | waiters | bani | aakhri bar badli |
|---|---|---|---|
| **Delivery Counter** | **3,4,1,5,6,7** | **2026-08-27** | 2026-09-28 10:33:27 |
| Khatri Biryani (owner) | *(khaali)* | 2026-08-29 | 2026-09-28 10:39:19 |

**Delivery Counter** ke account ne feature chalne ke din hi saaray waiters tick kar ke
save kar diya tha. Tab se us account ka Quick Report **har roz** takeaway aur delivery
gira raha hai — aur tanz ye ke Delivery Counter ko apne hi **delivery** orders nazar
nahi aate, kyunki delivery par waiter hota hi nahi.

Baaqi teen tenants (kashiffood, kashifkitchen, tawakalkashif) par kisi ne waiter tick
kar ke save nahi keya — is liye wahan abhi tak koi is se nahi takraya.

---

## 5. Hal — meri sifarish

### (a) Foran, bina deploy ke — us ek saved row ko saaf karna
`Delivery Counter` ki saved selection se `waiter_ids` hata dena. Ek row, sirf UI ki
pasand — na paisa, na stock, na journal. Is se us account ki report usi waqt theek
ho jayegi. **Aap ki ijazat darkar hai.**

### (b) Asal marammat (deploy ke sath)

1. **UI:** agar **saaray** waiter checkbox tick hain to `waiter_ids` bheja hi na jaye —
   theek wohi jo panel khud waada karta hai (*"Leave all unticked = every waiter"*).
   Yehi `order_types` ke liye bhi, yaksaaniyat ke liye.
2. **Panel me saaf tanbeeh:** *"Takeaway/Delivery orders par waiter nahi hota — waiter
   chunne par wo chhup jayenge."* Abhi ye baat kahin likhi nahi.
3. **Ek `Bila waiter` option** — taake jo chahe wo counter orders bhi shaamil kar sake.

**Kyun yehi tarteeb:** (1) modal ka apna waada poora karta hai aur report ke maani
nahi badalta; (3) us sahi soorat ko bhi rasta deta hai jahan waqai "sirf waiter wale
orders" chahiye. Sirf (1) karne se bhi shikayat khatam ho jati hai.

### Jo **nahi** karna chahiye
`whereIn` ko `whereIn(...) OR IS NULL` bana dena — is se "sirf Ali ke orders" maangne
par bhi saaray counter orders aa jayenge. Wo filter ko hi be-maani kar dega.

---

## 6. Guards (jo likhne hain)

1. Saaray waiters tick = koi waiter tick nahi — **ek jaisa** total.
2. Ek waiter tick = sirf usi ke orders (bila-waiter orders **na** aayen) — yani filter
   ab bhi kaam kare.
3. `order_types` par koi asar nahi (wo pehle se theek hai).
4. Kashif Food jaise tenant par, jahan har order bila-waiter hai, saaray waiters tick
   karne par report **poori** aaye — khaali nahi.
5. Saved selection restore hone par bhi wohi bartaao.

---

## 7. Risk

| khatra | haqeeqat |
|---|---|
| paisa / stock / journal | koi nahi — ye mehez report ka filter hai, sirf padhta hai |
| baaqi reports | `waiter_ids` sirf Quick Report bhejta hai; Report Center `waiter_id` (ek) bhejta hai, jo is se alag hai |
| dusray tenants | (a) sirf Khatri ke ek user ki row hai; (b) sab ke liye hai magar sirf tab harkat me aata hai jab koi waiter tick kare |

**Palatna:** (a) — row wapas likh dena. (b) — commit revert; koi migration nahi.

---

## 8. Jo banaya gaya (2026-09-28) — branch `fix/quick-report-waiter-null-20260928`

### (a) ✅ Ho gaya — prod par, bina deploy
`khatribiryani` → `pos_quick_report_settings` (user_id 3, **Delivery Counter**) me se
`waiter_ids` aur `order_types` khaali kar diye.

Purani qeemat mehfooz hai:
```json
{"sections":["categories"],"all_items":true,
 "waiter_ids":["3","4","1","5","6","7"],
 "order_types":["dine_in","takeaway","quick_sale","delivery"],
 "product_ids":[],"category_ids":[3,14,15,1,9,10,2,12,13]}
```

`order_types` bhi is liye khaali keya — abhi wo be-zarar tha, magar kal koi naya order type
bana to ye purani chaar naamon ki list usay bhi bahar rakh deti: wohi jaal, nayi shakl me.

**Nateeja:** usi selection se report ab 54 nahi, **150 qty / 65,960** — yani poora din.
Koi paisa, stock ya journal nahi chhua; sirf ek UI pasand ki row.

### (b) Code — deploy ka intezar

| file | kya |
|---|---|
| `SalesReportEngine.php` | `WAITER_NONE = 'none'` sentinel; `applyWaiterIds()` (dono call sites); `describeNarrowing()` |
| `SalesReportDocumentService.php` | `narrowing` ko document data me shaamil |
| `reports/center/print.blade.php` | **PARTIAL — NOT THE WHOLE DAY** banner + uska CSS |
| `pos/index.blade.php` | `No waiter (counter)` checkbox; tanbeeh ki satar; `checkedUnlessAll()` |

**Teen hisse, teen alag kaam:**

1. **`checkedUnlessAll()`** — saaray box tick = koi filter nahi. Panel ka apna waada
   (*"Leave all unticked = every waiter"*) ab sach hai. Yehi `order_types` par bhi, taake
   koi saved selection kal ke naye order type ko bahar na rakhe.
2. **`No waiter (counter)` option + sentinel** — `whereIn` ko `OR IS NULL` me nahi badla
   (§5 ki tanbeeh qaayam): bina sentinel ke chunaav ab bhi "sirf yehi waiter" hai, is liye
   "sirf Ali ke orders" waisa hi kaam karta hai. Sentinel apne `where()` group me lipta
   hai, warna `OR` bahar nikal kar branch/date/order-type ki shart bhi chauRi kar deta.
3. **PARTIAL banner** — ye §5 me nahi tha, tafteesh ke dauraan saamne aaya aur meri raye me
   sab se zaroori hissa hai. **Asal kharabi ye nahi thi ke report choti thi — ye thi ke
   choti report poori report jaisi dikhti thi.** Category aur item filter khud nazar aa jate
   hain (aap dekhte hain kaun si satarein chhapin); waiter aur order type ka koi nishan
   parche par nahi hota. Isi khamoshi ne ek mahina liya. Ab header khud bata deta hai.
   Report poori ho to banner hai hi nahi — Report Center aur raat wali PDF jaisi thin waisi
   hi rehti hain.

### Guards (7, sab likhe gaye)

**`PosQuickReportMySqlTest`** (asli controller ko call karte hain, query dobara nahi banate):
1. `test_orders_with_no_waiter_are_reachable_through_the_no_waiter_option` — poora din 600;
   sirf naam wale waiter = 300; sentinel ke sath wapas 600; akela sentinel = 300 aur bucket
   ka naam `Unassigned`; **aur akela Ali ab bhi sirf 100** (yehi satar `OR` ke leak hone par
   toote gi).
2. `test_a_day_where_no_order_has_a_waiter_is_not_emptied` — Kashif Food wali shakl.
3. `test_the_order_type_filter_is_unchanged` — jo theek tha wo theek rahe.
4. `test_a_narrowed_report_says_so_and_a_full_one_does_not` — **view render karta hai**, sirf
   data array nahi.
5. `test_a_saved_selection_keeps_the_no_waiter_option` — sentinel STRING hai; raste me kahin
   `intval` lag jaye to 0 ban kar purani kharabi chupke se wapas aa jati.

**`PosFrontendRegressionTest`** — (1) wala usool JS me hai, is liye uska pehra blade ke matn
par: `checkedUnlessAll` dono jagah lagi ho, purana `checked('.qr-waiter')` **na** ho, `none`
wala checkbox aur tanbeeh maujood ho.

### Jo abhi baqi hai
Tests chalana, blade compile + generated PHP lint, phir maalik ki ijazat par deploy.
