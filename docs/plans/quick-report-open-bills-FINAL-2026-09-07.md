# Quick Report me khule bills — AAKHRI tareeqa

**Tareekh:** 2026-09-07
**Ye MD kis liye:** owner ki baat do qadam me saaf hui, aur plan **do baar badla**. Pehla MD
(`quick-report-include-open-bills-2026-09-07.md`) galat na reh jaye, is liye ye us ki tasheeh hai.

---

## 1. Owner ki baat, teen qadam me

**Qadam 1** — "sirf quick report … mai draft and held wali b shamil hon.. ta k live current data
b assakte."

Main ne "jama me dikhao" samjha aur **alag figure** ka plan banaya: `POPULATION` ko chhero na,
saath me ek `openBills()` aggregate jor kar Overview par `+ Still Open` / `EXPECTED` dikhao.
Ye bana bhi (`fdad50a`, `9bb4d0e`) aur deploy bhi hua.

**Qadam 2** — owner ne poocha: "items count category k andar jo ara hai … wo b held draft sub ae
ga na?"

Main ne ginn kar dikhaya: **nahi**. Aur isi se na-mel ban raha tha —

```
KHATRI, 7 Sep 3:03 PM
  OVERVIEW    EXPECTED      69,390     <-- khule bills shaamil
  CATEGORIES  GRAND TOTAL   66,110     <-- shaamil NAHI
              farq           3,280
```

Owner: "overview nhi bhai sub mai karna tha" · "categories items, Items by Category" ·
"deals, order types, combos saray checkboxes b on kardon to held plus paid draft sub ana chahye".

**Qadam 3** — "design mai koi changes nhi ae ga … bs count ziyada hogae ga."

Yani **nayi satar bhi nahi chahiye**. Parchi ki shakl waisi hi, sirf hindse bare.

## 2. Jo bana — ek satar

Engine ki banawat ne raasta khud dikhaya:

```
salesBase()   <-- population ka faisla YAHIN
   |
   +-- overview()
   +-- linesBase() --> byCategory() · byItem() · byCategoryItems() · byDeal()
   +-- byWaiter() · byOrderType() · orderTypeCombos()
```

`linesBase()` khud `salesBase()` par `joinSub` karta hai. Is liye ek satar se **saat sections**
khud shaamil kar lete hain.

**Poora badlaav teen jagah:**

1. `normalizeFilters()` — nayi kunji `include_open`, **default false**
2. `salesBase()` —
   ```php
   ->whereIn('o.status', $f['include_open']
       ? array_merge(self::POPULATION, ['held', 'draft'])
       : self::POPULATION)
   ```
3. `PosQuickReportController::context()` — `'include_open' => true`, aur **kahin nahi**

`POPULATION` ki **qeemat nahi badli** — wo chaar aur jagah parhi jaati hai (SalesReportService,
RestaurantReportService, DashboardController) aur un ka koi call site chhua nahi gaya.

**Renderer me ek harf nahi badla** — na blade, na ESC/POS. Owner ne yehi kaha tha.

## 3. Qadam-1 ka kaam wapas nikala gaya

`openBills()`, `withOpen`, `open` kunji, aur dono renderers ki nayi satrein — sab **hata di gayin**.
Kyunke ab jama khud khule bills shaamil karta hai, to `EXPECTED` **dohri ginti** ban jati, aur
`of which` wali satar bhi owner ne nahi maangi.

Bacha hua faida: us kaam ne ye sikha diya ke `linesBase()` `salesBase()` par khara hai — jis se
aakhri tareeqa ek satar par sikur gaya.

## 4. Jo khud-ba-khud sach reh gaya

| Section | Kyun asar nahi para |
|---|---|
| **Returns** | `returnsBase()` `sales_returns` par chalta hai — khule bill ka return hota hi nahi |
| **Cash & Bank** | `payments` par chalta hai — khule bill par payment row hoti hi nahi |
| **Cancellations** | apni alag table — `cancelled` na khula hai na paid |

Ye teen **paid ka aaina** rahenge, aur yehi theek hai.

## 5. Owner ko yaad rehna chahiye

- **Quick Report ka total us din Report Center se ZYADA hoga** — jaan-boojh kar. Report Center
  kamaye hue paise ka aaina hai; Quick Report chal rahe kaam ka.
- **NET SALES aur Cash/Bank me farq aayega** — farq wohi paisa hai jo abhi aya nahi. Ab koi satar
  ye nahi batati (owner ne design nahi badalne ko kaha), to ye baat yaad rakhni paregi.
- **Hindse badalte rehte hain.** Owner ne khud dekha: pehli parchi par 3 khule bills / 2,810,
  agli ginti par 2 / 1,930.

## 6. Guard — shart badli, is liye test bhi

Pehla sab se ehem test tha "khule bills paid ke hindse na hilayein" — **wo ab galat hai**.
Nayi shart **Report Center** par hai:

- `test_report_center_is_untouched_by_open_bills` — khule bills mojood hone par Report Center ke
  **gyarah** hisse (overview, categories, items, categoryItems, deals, waiters, orderTypes, combos,
  cancellations, cashBank, bridge) hu-ba-hu wohi rahen
- `test_quick_report_counts_open_bills_in_every_section` — jama, Categories ka amount, Items ki qty,
  Items-by-Category aur Order Types sab barhein
- `test_cash_bank_never_grows_with_open_bills`
- `test_a_cancelled_bill_is_never_counted`
- `test_open_bills_honour_the_same_filters` — category filter khule bills par bhi lage (catA: paid
  100 + khula 500 = 600)

Dono taraf se RED hote dekha: `include_open` bhejna band kiya to Quick Report ka test toota;
population ka faisla hataya to bhi toota.

## 7. Do ghaltiyan jo raaste me huin (dono pakdi gayin)

- **Blade khali kar diya tha.** Mera regex fail hua, `preg_replace` ne `NULL` lauta diya, aur maine
  wo seedha file me likh diya. Backup se bahal, phir `git stash` istemal kiya — wohi sahi tareeqa.
- **`salesBase()` kaat diya tha.** `openBills()` hataane wala regex zyada kha gaya; PHP lint pass
  ho gaya kyunke ghaib method syntax error nahi hoti. `grep` se pakra (`salesBase` 0 baar, magar 7
  jagah bulaya ja raha), `git checkout` se bahal, aur phir **regex chhor kar exact string** se kaam
  kiya — aur script me shart daali ke MISS par kuch **likha hi na jaye**.
