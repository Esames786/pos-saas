# Quick Report me khule bills bhi — sirf POS wali screen par

**Tareekh:** 2026-09-07 · **Maanga:** owner
**Halat:** research mukammal (prod ke live data par), code likhna baqi

---

## 1. Owner ki baat

> "quick report ya baqi report center overall her jaga sirf paid order ko hum consider
> karte hain. sirf quick report pos wali screen mai jitne b reports nikal rahein hain us
> mai draft and held wali b shamil hon.. ta k live current data b assakte. report center
> ya dosri report ko nhi cherna."

Yani: **sirf** POS ki Quick Report me held + draft shamil hon. Report Center aur baqi
reports bilkul jaisi hain waisi rahen.

## 2. Aaj kya ho raha hai

Quick Report apna data usi engine se leti hai jo Report Center leta hai
(`PosQuickReportController` -> `SalesReportEngine`), aur population **ek hi jagah** tay hoti hai:

```php
// SalesReportEngine.php:30
public const POPULATION = ['paid', 'partially_returned', 'returned'];

// SalesReportEngine::salesBase() — har section isi se guzarta hai
->whereIn('o.status', self::POPULATION)
```

`normalizeFilters()` ki key-list me status ka koi khaana nahi — yani abhi caller ke paas
population badalne ka koi raasta hi nahi.

⚠️ **Wohi constant chaar aur jagah parha jaata hai** — inhein chhedna mana hai:

| Kahan | Kya |
|---|---|
| `SalesReportEngine::salesBase()` | Report Center ke saare sections |
| `SalesReportService` (4 jagah) | legacy sales reports, channels/riders, dashboard ka 7-din |
| `RestaurantReportService` (3 jagah) | waiter / table / floor reports |
| `DashboardController` | tiles |

## 3. Khatri ka nateeja — aur us se nikli ehem baat

Owner ne Khatri ka before/after maanga tha. Ginti:

```
KHATRI — kisi bhi business date par held/draft orders:  0 din, 0 orders
```

Wajah: held bill pay hote hi **wohi row** `paid` ban jaati hai (row update hoti hai, nayi
nahi banti — `sale_uuid` bhi wohi rehta hai). Is liye guzray hue kisi din par kuch held
nahi bachta.

**Natija: held/draft "tareekh" ki cheez nahi, "is lamhe" ki cheez hai.** Guzray din ke
Quick Report par is tabdeeli ka asar **sifar** hoga. Faida sirf **chalti service** ke
doran hai — aur wohi owner maang rahe hain ("live current data").

Is liye before/after **live** lena para, aur Kashif Food par mil gaya.

## 4. KASHIF FOOD — asli before/after (7 September, 12:47 PM Karachi)

**BEFORE** — ye hindse engine ne khud diye (`overview()` / `byOrderType()` / `cashBank()`):

```
orders                3
net_sales             2,910.00
returns               0.00
cash_in               2,910.00

order type:  Takeaway  3 orders  2,910.00
             (dine_in aur delivery bilkul GHAYAB — halanke un me 6,640 baitha hai)
```

**DELTA** — 7 held bills, us waqt mezon/counter par:

```
orders                7
grand_total           9,990.00
payment rows          0        <-- khule bill par paisa abhi diya nahi gaya

order type:  dine_in    2 orders  4,290.00
             takeaway   2 orders  3,350.00
             delivery   3 orders  2,350.00

category:    Singaporean Rice          8,000.00
             Sandwiches                  950.00
             Chicken Malai Boti Roll     390.00
             Beef Boti Rolls             370.00
             Beverages                    80.00

bills:  #3200 dine_in  3,240   #3201 delivery   950   #3202 takeaway 2,750
        #3203 takeaway   600   #3205 dine_in  1,050   #3206 delivery  800
        #3209 delivery   600
```

**AFTER** (hisab: before + delta):

```
orders            3        ->  10
net_sales         2,910.00 ->  12,900.00
cash_in           2,910.00 ->  2,910.00   (NAHI badlega — section 6 dekhein)
```

Yani beech-e-service Quick Report asli tasveer ka **sirf 23%** dikha rahi thi. Aur
order-type section me **dine_in aur delivery ka naam bhi nahi tha**, halanke un dono me
6,640 rupay ka kaam chal raha tha.

## 5. Tajweez — ek nayi filter key, default band

`normalizeFilters()` me ek key barhao: `include_open` (default **false**), aur `salesBase()`
me population usi ke hisab se:

```php
'include_open' => (bool) ($raw['include_open'] ?? false),
...
->whereIn('o.status', $f['include_open']
    ? array_merge(self::POPULATION, ['held', 'draft'])
    : self::POPULATION)
```

Aur `include_open => true` **sirf** `PosQuickReportController::filters()` bhejega.

**Kyun ye shakl:** default `false` hone ki wajah se Report Center, legacy reports, dashboard
aur restaurant reports **banawat ke tor par** achhoote rehte hain — un ka koi call site
badalta hi nahi. Bilkul wohi tareeqa jo CATEGORY-BRANCH-SCOPE-1 me `branch_id = NULL` ka
tha: nayi soorat sirf wahan chalti hai jahan maangi jaye.

## 6. ⚠️ Teen baatein jo owner ko qabool karni hongi

**(a) Cash / Card / Bank khule bills ko NAHI ginega.**
Khule bill par `sale_payments` ki koi row hoti hi nahi (upar: 0 rows) — customer ne abhi
tareeqa chuna hi nahi. Is liye:

```
net_sales   12,900.00
cash_in      2,910.00   <-- 9,990 ka farq
```

Ye keera nahi, sach hai: wo paisa **aya nahi**. Magar report par do hindse alag nazar
aayenge, aur ye pehle se bata dena zaroori hai.

**(b) Quick Report ka total us din Report Center se JAAN-BOOJH KAR alag hoga.**
Owner yehi maang rahe hain, magar likh kar rakhna chahiye — warna kal koi poochega ke
"POS 12,900 keh raha hai, Report Center 2,910 kyun?".

**(c) Khule bill ke hindse kamaye hue nahi hain.**
Held bill badalta rehta hai — item barhta hai, cancel hota hai, discount lagta hai, total
round hota hai. Do minute baad Quick Report doosra hindsa dega. Ye "aya hua paisa" nahi,
"chal raha kaam" hai.

**Isi liye mera mashwara:** khule bills ko chupke se paid me mila dene ki bajaye **alag
nishan** ke saath dikhaya jaye — jaise dashboard par pehle se hota hai (`+ 33 still open`,
DASHBOARD-OPEN-BILLS-1). Owner ka maqsad (live tasveer) poora hota hai aur "kamaya hua"
aur "chal raha" gadd-madd nahi hote. Agar owner phir bhi ek hi jama chahen to wo bhi ho
jayega — magar faisla likha hua ho.

## 7. Kya haath NAHI lagana

- `SalesReportEngine::POPULATION` ki qeemat — chaar aur jagah isay parhti hain
- Report Center ke apne call sites
- `SalesReportService` / `RestaurantReportService` / `DashboardController`
- Returns ka hisab: khule bill par return ho hi nahi sakta, is liye wahan kuch nahi badalta

## 8. Guard

- Ek held aur ek draft bill banao. `include_open` ke **baghair** engine ka jawab hu-ba-hu
  wohi rahe (yehi wo test hai jo Report Center ki hifazat karta hai).
- `include_open => true` par orders aur net_sales dono barhen.
- Cash/bank section **na** badle (khule bill par payment nahi).
- Cancelled bill kisi soorat me shaamil na ho — `held/draft` ka matlab `cancelled` nahi.
- Report Center ka apna raasta chala kar dekho ke uska hindsa nahi hila.
